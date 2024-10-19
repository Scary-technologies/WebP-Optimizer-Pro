<?php
/**
 * Plugin Name: WebP Optimizer Pro
 * Description: افزونه‌ای پیشرفته برای بهینه‌سازی و تبدیل تصاویر آپلود شده به فرمت WebP.
 * Version: 1.0.0
 * Author: Scary Technologies
 * <a href="https://github.com/Scary-technologies/WebP-Optimizer-Pro" target="_blank">مشاهده ریپوزیتوری در GitHub</a>
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SimpleWebPConverter {

    public function __construct() {
        add_filter( 'wp_handle_upload', [ $this, 'convert_on_upload' ] );
        add_action( 'admin_menu', [ $this, 'add_media_bulk_convert_option' ] );
        add_action( 'admin_post_convert_to_webp', [ $this, 'bulk_convert_to_webp' ] );
        add_action( 'bulk_actions-upload', [ $this, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_bulk_action' ], 10, 3 );
    }

    public function register_bulk_action( $bulk_actions ) {
        $bulk_actions['convert_to_webp'] = 'تبدیل به WebP';
        return $bulk_actions;
    }

    public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'convert_to_webp' ) {
            return $redirect_to;
        }

        foreach ( $post_ids as $post_id ) {
            $file_path = get_attached_file( $post_id );
            $converted = $this->convert_to_webp( $file_path );
            if ( $converted ) {
                $this->add_image_to_media_library( $converted, [ 'url' => wp_get_attachment_url( $post_id ) ] );
            }
        }

        $redirect_to = add_query_arg( 'converted', count( $post_ids ), $redirect_to );
        return $redirect_to;
    }

    public function convert_on_upload( $upload ) {
        if ( ! isset( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
            return $upload;
        }

        $file_path = $upload['file'];
        $info = pathinfo( $file_path );
        $extension = strtolower( $info['extension'] );
        if ( ! in_array( $extension, [ 'jpg', 'jpeg', 'png' ] ) ) {
            return $upload;
        }

        $converted = $this->convert_to_webp( $file_path );
        if ( $converted ) {
            $upload['file'] = $converted;
            $upload['type'] = 'image/webp';
            $this->add_image_to_media_library( $converted, $upload );
            unlink( $file_path ); // Delete original file after conversion
        }
        return $upload;
    }

    private function convert_to_webp( $file_path ) {
        $info = pathinfo( $file_path );
        $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
        if ( file_exists( $webp_path ) ) {
            return false; // Avoid duplicate conversion
        }
        $info = pathinfo( $file_path );
        $extension = strtolower( $info['extension'] );
        if ( in_array( $extension, [ 'jpg', 'jpeg', 'png' ] ) ) {
            $image = false;
            if ( $extension == 'jpg' || $extension == 'jpeg' ) {
                $image = @imagecreatefromjpeg( $file_path );
            } elseif ( $extension == 'png' ) {
                $image = @imagecreatefrompng( $file_path );
                imagepalettetotruecolor( $image );
            }

            if ( $image ) {
                $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
                if ( function_exists( 'imagewebp' ) && imagewebp( $image, $webp_path, 80 ) ) {
                    imagedestroy( $image );
                    return $webp_path;
                }
                imagedestroy( $image );
            }
        }
        return false;
    }

    private function add_image_to_media_library( $file_path, $upload ) {
        $filetype = wp_check_filetype( basename( $file_path ), null );
        $attachment = [
            'guid'           => $upload['url'],
            'post_mime_type' => $filetype['type'],
            'post_title'     => preg_replace( '/.[^.]+$/', '', basename( $file_path ) ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment( $attachment, $file_path );
        // Removed metadata generation to avoid creating multiple sizes for WebP images
    }

    public function add_media_bulk_convert_option() {
        add_media_page( 'تبدیل به WebP', 'تبدیل به WebP', 'manage_options', 'convert_to_webp', [ $this, 'render_bulk_convert_page' ] );
    }

    public function render_bulk_convert_page() {
        ?>
        <div class="wrap">
            <h1>تبدیل تصاویر به WebP</h1>
            <?php if ( isset( $_GET['success'] ) && $_GET['success'] == 1 ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>تمامی تصاویر با موفقیت به فرمت WebP تبدیل شدند.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);">
                <input type="hidden" name="action" value="convert_to_webp">
                <div style="margin-bottom: 15px;">
                    <label for="quality" style="font-weight: bold;">کیفیت تصویر (0-100):</label>
                    <input type="number" id="quality" name="quality" value="80" min="0" max="100" style="width: 100px;">
                </div>
                <?php submit_button( 'تبدیل همه تصاویر', 'primary', '', false, [ 'style' => 'background-color: #0073aa; border-color: #0073aa; color: #fff; padding: 10px 20px; font-size: 16px;' ] ); ?>
            </form>
            <div id="conversion-progress" style="margin-top: 20px; display: none;">
                <h2>پیشرفت تبدیل</h2>
                <progress id="progress-bar" value="0" max="100" style="width: 100%; height: 30px;"></progress>
                <p id="progress-status" style="font-weight: bold; text-align: center; margin-top: 10px;"></p>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.querySelector('form');
                    form.addEventListener('submit', function(event) {
                        event.preventDefault();
                        const progressBar = document.getElementById('progress-bar');
                        const progressStatus = document.getElementById('progress-status');
                        const progressContainer = document.getElementById('conversion-progress');

                        progressContainer.style.display = 'block';
                        progressStatus.textContent = 'در حال آماده‌سازی...';

                        fetch('<?php echo admin_url( 'admin-post.php' ); ?>', {
                            method: 'POST',
                            body: new FormData(form)
                        }).then(response => {
                            if (response.ok) {
                                progressBar.value = 100;
                                progressStatus.textContent = 'تبدیل همه تصاویر به پایان رسید.';
                            } else {
                                progressStatus.textContent = 'خطایی در فرآیند تبدیل رخ داده است.';
                            }
                        });
                    });
                });
            </script>
        </div>
        <div class="wrap">
            <h1>تبدیل تصاویر به WebP</h1>
            <?php if ( isset( $_GET['success'] ) && $_GET['success'] == 1 ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>تمامی تصاویر با موفقیت به فرمت WebP تبدیل شدند.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="convert_to_webp">
                <label for="quality">کیفیت تصویر (0-100):</label>
                <input type="number" id="quality" name="quality" value="80" min="0" max="100">
                <br><br>
                <?php submit_button( 'تبدیل همه تصاویر' ); ?>
            </form>
            <div id="conversion-progress" style="margin-top: 20px; display: none;">
                <h2>پیشرفت تبدیل</h2>
                <progress id="progress-bar" value="0" max="100" style="width: 100%;"></progress>
                <p id="progress-status"></p>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.querySelector('form');
                    form.addEventListener('submit', function(event) {
                        event.preventDefault();
                        const progressBar = document.getElementById('progress-bar');
                        const progressStatus = document.getElementById('progress-status');
                        const progressContainer = document.getElementById('conversion-progress');

                        progressContainer.style.display = 'block';
                        progressStatus.textContent = 'در حال آماده‌سازی...';

                        fetch('<?php echo admin_url( 'admin-post.php' ); ?>', {
                            method: 'POST',
                            body: new FormData(form)
                        }).then(response => {
                            if (response.ok) {
                                progressBar.value = 100;
                                progressStatus.textContent = 'تبدیل همه تصاویر به پایان رسید.';
                            } else {
                                progressStatus.textContent = 'خطایی در فرآیند تبدیل رخ داده است.';
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    public function bulk_convert_to_webp() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی غیرمجاز' );
        }

        $quality = isset( $_POST['quality'] ) ? intval( $_POST['quality'] ) : 80;

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png' ],
            'numberposts'    => -1,
        ] );

        $total_attachments = count( $attachments );
        $processed_count = 0;

        foreach ( $attachments as $attachment ) {
            $file_path = get_attached_file( $attachment->ID );
            $converted = $this->convert_to_webp_with_quality( $file_path, $quality );
            if ( $converted ) {
                $this->add_image_to_media_library( $converted, [ 'url' => wp_get_attachment_url( $attachment->ID ) ] );
                unlink( $file_path ); // Delete original file after conversion
            }
            $processed_count++;
            $this->update_progress( $processed_count, $total_attachments );
        }

        wp_redirect( admin_url( 'upload.php?page=convert_to_webp&success=1' ) );
        exit;
    }

    private function convert_to_webp_with_quality( $file_path, $quality ) {
        $info = pathinfo( $file_path );
        $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
        if ( file_exists( $webp_path ) ) {
            return false; // Avoid duplicate conversion
        }
        $info = pathinfo( $file_path );
        $extension = strtolower( $info['extension'] );
        if ( in_array( $extension, [ 'jpg', 'jpeg', 'png' ] ) ) {
            $image = false;
            if ( $extension == 'jpg' || $extension == 'jpeg' ) {
                $image = @imagecreatefromjpeg( $file_path );
            } elseif ( $extension == 'png' ) {
                $image = @imagecreatefrompng( $file_path );
                imagepalettetotruecolor( $image );
            }

            if ( $image ) {
                $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
                if ( function_exists( 'imagewebp' ) && imagewebp( $image, $webp_path, $quality ) ) {
                    imagedestroy( $image );
                    return $webp_path;
                }
                imagedestroy( $image );
            }
        }
        return false;
    }

    private function update_progress( $processed, $total ) {
        $progress = ( $processed / $total ) * 100;
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const progressBar = document.getElementById("progress-bar");
                const progressStatus = document.getElementById("progress-status");
                progressBar.value = ' . $progress . ';
                progressStatus.textContent = "تبدیل ' . $processed . ' از ' . $total . ' تصویر انجام شد.";
            });
        </script>';
        flush();
    }
}

new SimpleWebPConverter();
