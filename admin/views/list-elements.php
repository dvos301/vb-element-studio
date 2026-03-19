<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap vb-es-wrap">
    <h1 class="wp-heading-inline">VB Element Studio</h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=vb-es-edit' ) ); ?>" class="page-title-action">Add New</a>
    <hr class="wp-header-end" />

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Element deleted successfully.</p></div>
    <?php endif; ?>

    <?php if ( empty( $elements ) ) : ?>
        <div class="vb-es-empty-state">
            <p>No custom elements yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=vb-es-edit' ) ); ?>">Create your first element</a>.</p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-primary">Element Name</th>
                    <th scope="col">Shortcode</th>
                    <th scope="col">Parameters</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $elements as $el ) :
                    $params_raw = get_post_meta( $el->ID, '_vb_params', true );
                    $params = json_decode( $params_raw, true );
                    $param_count = is_array( $params ) ? count( $params ) : 0;
                    $shortcode_tag = get_post_meta( $el->ID, '_vb_base_tag', true ) ?: $el->post_name;
                    $edit_url = admin_url( 'admin.php?page=vb-es-edit&element_id=' . $el->ID );
                    $delete_url = wp_nonce_url(
                        admin_url( 'admin.php?page=vb-element-studio&vb_es_delete=' . $el->ID ),
                        'vb_es_delete_element'
                    );
                ?>
                    <tr>
                        <td class="column-primary">
                            <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $el->post_title ); ?></a></strong>
                        </td>
                        <td><code>[<?php echo esc_html( $shortcode_tag ); ?>]</code></td>
                        <td><?php echo esc_html( $param_count ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>">Edit</a>
                            |
                            <a href="<?php echo esc_url( $delete_url ); ?>" class="vb-es-delete-link" onclick="return confirm('Are you sure you want to delete this element?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
