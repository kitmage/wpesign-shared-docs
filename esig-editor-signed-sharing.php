<?php
/**
 * Plugin Name: KitMage Signed PDF Sharing
 * Description: Share signed document visibility between approved WordPress Editors inside the WP E-Signature My Documents dashboard; review cross-owned documents as secured PDFs.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Plugin URI: https://kitmage.com
 * Author: Mike@KitMage
 * Author URI: https://kitmage.com
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Companion plugin for WP E-Signature 2.1.7.4.
 * Not a substitute for production integration testing or a security audit.
 */
final class ESIG_Editor_Signed_Sharing {
    private $signed_total = null;
    private $search_total = null;
    private $current_shared_ids = array();
    private $is_eligible = null;
    private $editor_ids = null;

    public function __construct() {
        add_action( 'esig_documents_loading', array( $this, 'prime_signed_count' ), 1 );
        add_filter( 'esig-document-index-docs', array( $this, 'replace_signed_documents' ), 100, 2 );
        add_filter( 'esig_document_preview_link', array( $this, 'shared_preview_link' ), 100, 2 );
        add_filter( 'esig-document-index-footer', array( $this, 'append_dashboard_adjustments' ), 100, 2 );
        add_action( 'admin_post_esig_editor_signed_pdf', array( $this, 'serve_signed_pdf' ) );
        add_action( 'admin_init', array( $this, 'block_legacy_mutation_routes' ), 0 );

        foreach ( array(
            'trash_document',
            'delete_document',
            'restore_document',
            'resend_document',
            'bulk_actions',
        ) as $action ) {
            add_action( 'wpesignature_ajax_' . $action, array( $this, 'block_shared_document_mutations' ), 0, 1 );
        }
    }

    private function is_signed_dashboard() {
        if ( ! is_admin() ) {
            return false;
        }
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] )
            ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $status = isset( $_GET['document_status'] ) && is_scalar( $_GET['document_status'] )
            ? sanitize_key( wp_unslash( $_GET['document_status'] ) ) : 'awaiting';
        return 'esign-docs' === $page && 'signed' === $status;
    }

    private function eligible_editor() {
        if ( null !== $this->is_eligible ) {
            return $this->is_eligible;
        }
        $this->is_eligible = false;
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        $wp_user = wp_get_current_user();
        if ( ! $wp_user || ! in_array( 'editor', (array) $wp_user->roles, true ) ) {
            return false;
        }
        if ( ! class_exists( '\\WpEsignature\\Models\\User' ) ||
             ! class_exists( '\\WpEsignature\\Models\\Document' ) ) {
            return false;
        }
        $this->is_eligible = (bool) \WpEsignature\Models\User::getInstance()
            ->checkEsigAdmin( get_current_user_id() );
        return $this->is_eligible;
    }

    /** IDs of current WordPress Editors, with a fallback to the current user. */
    private function permitted_owner_ids() {
        if ( null !== $this->editor_ids ) {
            return $this->editor_ids;
        }
        $this->editor_ids = array_values( array_unique( array_map( 'absint', get_users( array(
            'role'   => 'editor',
            'fields' => 'ID',
        ) ) ) ) );
        $this->editor_ids[] = get_current_user_id();
        $this->editor_ids = array_values( array_unique( array_filter( $this->editor_ids ) ) );
        return $this->editor_ids;
    }

    private function dashboard_query_params() {
        $requested_order = isset( $_GET['orderby'] ) && is_scalar( $_GET['orderby'] )
            ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'document_id';
        $allowed_order = array( 'document_id', 'document_title', 'date_created', 'last_modified', 'user_id' );
        $order_by = in_array( $requested_order, $allowed_order, true ) ? $requested_order : 'document_id';
        $order = isset( $_GET['order'] ) && is_scalar( $_GET['order'] ) &&
            'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
        $page = isset( $_GET['pagenum'] ) ? max( 1, absint( $_GET['pagenum'] ) ) : 1;
        $limit = class_exists( '\\WpEsignature\\Models\\General' )
            ? (int) \WpEsignature\Models\General::get_doc_display_number() : 20;
        $limit = max( 1, $limit );
        $search = '';
        if ( ! empty( $_GET['esig_search'] ) && isset( $_GET['esig_document_search'] ) &&
            is_scalar( $_GET['esig_document_search'] ) ) {
            $search = trim( sanitize_text_field( wp_unslash( $_GET['esig_document_search'] ) ) );
        }
        return array( $order_by, $order, $page, $limit, $search );
    }

    /**
     * Builds a document SQL query using WP E-Signature's own tables.
     * The EXISTS clause prevents duplicate documents for multiple signers.
     */
    private function query_signed_documents( $count_only = false, $search = '', $page = 1, $limit = 20,
        $order_by = 'document_id', $order = 'DESC' ) {
        global $wpdb;
        $ids = $this->permitted_owner_ids();
        if ( ! $ids ) {
            return $count_only ? 0 : array();
        }
        $table = $wpdb->prefix . 'esign_documents';
        $signers = $wpdb->prefix . 'esign_document_users';
        $id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        $args = array_merge( array( 'signed' ), $ids );
        $sql = 'SELECT ' . ( $count_only ? 'COUNT(*)' : 'd.*' ) . " FROM {$table} AS d " .
            "WHERE d.document_status = %s AND d.user_id IN ({$id_placeholders})";
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $sql .= " AND (d.document_title LIKE %s OR EXISTS (SELECT 1 FROM {$signers} AS du " .
                'WHERE du.document_id = d.document_id AND du.signer_name LIKE %s))';
            $args[] = $like;
            $args[] = $like;
        }
        if ( ! $count_only ) {
            // Identifiers are selected from a fixed whitelist above; never interpolate raw user input.
            $allowed_order = array( 'document_id', 'document_title', 'date_created', 'last_modified', 'user_id' );
            $order_by = in_array( $order_by, $allowed_order, true ) ? $order_by : 'document_id';
            $order = 'ASC' === $order ? 'ASC' : 'DESC';
            $sql .= " ORDER BY d.{$order_by} {$order}, d.document_id DESC LIMIT %d OFFSET %d";
            $args[] = $limit;
            $args[] = max( 0, $page - 1 ) * $limit;
        }
        $prepared = $wpdb->prepare( $sql, $args );
        return $count_only ? (int) $wpdb->get_var( $prepared ) : (array) $wpdb->get_results( $prepared );
    }

    public function prime_signed_count() {
        if ( ! $this->is_signed_dashboard() || ! $this->eligible_editor() ) {
            return;
        }
        $this->signed_total = $this->query_signed_documents( true );
        if ( defined( 'ESIG_CACHE_GROUP' ) ) {
            // WP E-Signature reads this object-cache key before its own cached transient.
            // Priming it per request keeps the Signed tab count consistent with the shared list.
            wp_cache_set( 'esig_document_total_user_' . get_current_user_id() . '_signed',
                $this->signed_total, ESIG_CACHE_GROUP );
        }
    }

    public function replace_signed_documents( $docs, $args ) {
        if ( ! $this->is_signed_dashboard() || ! $this->eligible_editor() ) {
            return $docs;
        }
        list( $order_by, $order, $page, $limit, $search ) = $this->dashboard_query_params();
        $this->current_shared_ids = array();
        $result = $this->query_signed_documents( false, $search, $page, $limit, $order_by, $order );
        foreach ( $result as $document ) {
            if ( (int) $document->user_id !== get_current_user_id() ) {
                $this->current_shared_ids[] = (int) $document->document_id;
            }
        }
        $this->search_total = $this->query_signed_documents( true, $search );
        return $result;
    }

    private function shared_document_is_allowed( $document_id ) {
        if ( ! $document_id || ! $this->eligible_editor() ) {
            return false;
        }
        $document = \WpEsignature\Models\Document::getInstance()->getDocument( $document_id );
        if ( ! $document || 'signed' !== $document->document_status ) {
            return false;
        }
        $owner_id = (int) $document->user_id;
        return in_array( $owner_id, $this->permitted_owner_ids(), true );
    }

    private function shared_document_is_other_editor( $document_id ) {
        if ( ! $this->shared_document_is_allowed( $document_id ) ) {
            return false;
        }
        $owner = (int) \WpEsignature\Models\Document::getInstance()->get_document_owner_id( $document_id );
        return $owner !== get_current_user_id();
    }

    private function signed_pdf_url( $document_id ) {
        return add_query_arg( array(
            'action'      => 'esig_editor_signed_pdf',
            'document_id' => $document_id,
            '_wpnonce'    => wp_create_nonce( 'esig_editor_signed_pdf_' . $document_id ),
        ), admin_url( 'admin-post.php' ) );
    }

    public function shared_preview_link( $link, $args ) {
        if ( ! $this->is_signed_dashboard() || ! $this->eligible_editor() ||
            empty( $args['document'] ) || empty( $args['document']->document_id ) ) {
            return $link;
        }
        $doc_id = absint( $args['document']->document_id );
        if ( ! in_array( $doc_id, $this->current_shared_ids, true ) ||
            ! $this->shared_document_is_other_editor( $doc_id ) ) {
            return $link;
        }
        return $this->signed_pdf_url( $doc_id );
    }

    public function append_dashboard_adjustments( $footer, $args ) {
        if ( ! $this->is_signed_dashboard() || ! $this->eligible_editor() ) {
            return $footer;
        }
        list( $order_by, $order, $page, $limit, $search ) = $this->dashboard_query_params();
        $total = null !== $this->search_total ? $this->search_total : $this->query_signed_documents( true, $search );
        $pages = (int) ceil( $total / $limit );
        $extra = '<style>.wpesign__mydocs-function-lower .tablenav-pages{display:none!important}' .
            '.esig-editor-shared-pager{padding:12px 0;text-align:right}.esig-editor-shared-pager .page-numbers{display:inline-block;margin:0 4px;padding:4px 8px}' .
            '</style>';

        if ( $pages > 1 ) {
            $base = add_query_arg( 'pagenum', '%#%' );
            $links = paginate_links( array(
                'base'    => $base,
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
                'type'    => 'plain',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            if ( $links ) {
                $extra .= '<nav class="esig-editor-shared-pager" aria-label="Shared signed documents pages">' . $links . '</nav>';
            }
        }
        if ( $this->current_shared_ids ) {
            // Core generates Trash links for every row. Strip cross-owned row actions and
            // selection controls in the UI; the server-side handlers are also guarded.
            $extra .= '<script>(function(){const ids=' . wp_json_encode( array_values( $this->current_shared_ids ) ) . ';'
                . 'function protect(){ids.forEach(function(id){const row=document.getElementById("wpesign__document-"+id);if(!row)return;'
                . 'const c=row.querySelector("input.wpesign__selectall_checkbox_child");if(c){c.checked=false;c.disabled=true;c.style.display="none";}'
                . 'const area=row.querySelector(".row-actions");const link=row.querySelector("a.row-title");'
                . 'if(area&&link){area.replaceChildren();const span=document.createElement("span");span.className="active";'
                . 'const a=document.createElement("a");a.href=link.href;a.target="_blank";a.rel="noopener noreferrer";a.textContent="View signed PDF (shared)";'
                . 'span.appendChild(a);area.appendChild(span);}});}'
                . 'protect();document.addEventListener("DOMContentLoaded",protect);})();</script>';
        }
        return $footer . $extra;
    }

    /** Stop shared document mutation attempts before ApproveMe's own handlers execute. */
    public function block_shared_document_mutations( $data ) {
        if ( ! $this->eligible_editor() || ! is_object( $data ) || ! method_exists( $data, 'get' ) ) {
            return;
        }
        $ids = $data->get( 'documentIds' );
        if ( ! is_array( $ids ) ) {
            $ids = array( $data->get( 'documentId' ) );
        }
        foreach ( $ids as $id ) {
            $doc_id = absint( $id );
            if ( $doc_id && $this->shared_document_is_other_editor( $doc_id ) ) {
                wp_send_json_error( array(
                    'message' => 'Shared signed documents are read-only. Ask the document owner to make changes.',
                ), 403 );
            }
        }
    }

    /** Guard legacy controller URLs in addition to the newer AJAX handlers. */
    public function block_legacy_mutation_routes() {
        if ( ! $this->eligible_editor() || ! isset( $_GET['page'], $_GET['document_id'] ) ||
             ! is_scalar( $_GET['page'] ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        $method = isset( $_GET['method'] ) && is_scalar( $_GET['method'] )
            ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';
        $mutation = in_array( $page, array(
            'esign-delete-documents', 'esign-trash-documents',
            'esign-restore-documents', 'esign-resend-documents',
        ), true ) || ( 'esign-docs' === $page && in_array( $method,
            array( 'delete', 'trash', 'restore', 'resend' ), true ) );
        if ( $mutation && $this->shared_document_is_other_editor( absint( $_GET['document_id'] ) ) ) {
            wp_die( esc_html__( 'Shared signed documents are read-only.', 'esig' ),
                '', array( 'response' => 403 ) );
        }
    }

    /** Permission-checked browser PDF viewer; never hands out an unauthenticated link. */
    public function serve_signed_pdf() {
        $id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0;
        if ( ! $id || ! $this->shared_document_is_allowed( $id ) ) {
            wp_die( esc_html__( 'You do not have access to this signed document.', 'esig' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'esig_editor_signed_pdf_' . $id );
        if ( ! class_exists( '\\WpEsignPdf\\Helpers\\Pdf\\GeneratePdf' ) ||
             ! class_exists( '\\WpEsignPdf\\Helpers\\Pdf\\PdfSettings' ) ) {
            wp_die( esc_html__( 'Enable the WP E-Signature Save as PDF add-on to review shared signed documents.', 'esig' ), '', array( 'response' => 503 ) );
        }
        ob_start(); // Isolate any renderer output so binary PDF headers remain valid.
        try {
            $filename = \WpEsignPdf\Helpers\Pdf\PdfSettings::getInstance()->pdf_file_name( $id );
            $filename = sanitize_file_name( ( $filename ? $filename : 'signed-document-' . $id ) . '.pdf' );
            $pdf = \WpEsignPdf\Helpers\Pdf\GeneratePdf::getInstance()->create( $id, $filename, 'S' );
            if ( ! is_string( $pdf ) || '' === $pdf ) {
                throw new \RuntimeException( 'PDF renderer returned no document.' );
            }
        } catch ( \Throwable $exception ) {
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            error_log( 'ESIG Editor Signed Sharing PDF error (document ' . $id . '): ' . $exception->getMessage() );
            wp_die( esc_html__( 'The signed PDF could not be generated. Please contact your administrator.', 'esig' ), '', array( 'response' => 500 ) );
        }
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . str_replace( array( '"', "\r", "\n" ), '', $filename ) . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate' );
        echo $pdf; // Binary PDF from the installed WP E-Signature renderer.
        exit;
    }
}

add_action( 'plugins_loaded', static function() {
    new ESIG_Editor_Signed_Sharing();
}, 99 );
