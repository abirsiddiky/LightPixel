<?php
/**
 * Plugin Name: LightPixel - Image Optimizer
 * Description: Automatically convert and manually optimize images to WebP and AVIF formats for better performance
 * Version: 1.1.0
 * Author: Abir Siddiky
 * Author URI: https://abirsiddiky.com/
 * License: GPL v2 or later
 * Text Domain: lightpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LightPixel_Optimizer {

    private $option_name = 'lightpixel_settings';
    private $log_file;

    public function __construct() {
        // Set up log file
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/lightpixel-log.txt';

        // Automatic conversion
        add_filter( 'wp_handle_upload', array( $this, 'auto_convert_image' ) );

        // Admin menu and settings
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

        // AJAX handlers for bulk conversion
        add_action( 'wp_ajax_lightpixel_get_images', array( $this, 'ajax_get_images' ) );
        add_action( 'wp_ajax_lightpixel_convert_image', array( $this, 'ajax_convert_image' ) );
        add_action( 'wp_ajax_lightpixel_save_settings', array( $this, 'ajax_save_settings' ) );

        // Add bulk action to media library
        add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) );

        // Increase upload limits for large images
        add_filter( 'upload_size_limit', array( $this, 'increase_upload_limit' ) );
    }

    /**
     * Log errors for debugging
     */
    private function log_error( $message ) {
        $timestamp = gmdate( 'Y-m-d H:i:s' );
        $log_message = "[{$timestamp}] {$message}\n";
        file_put_contents( $this->log_file, $log_message, FILE_APPEND );
    }

    /**
     * Increase memory limit for large images
     */
    private function increase_memory_limit() {
        $current_limit = ini_get( 'memory_limit' );
        $current_limit_mb = intval( $current_limit );

        if ( $current_limit_mb < 256 ) {
            @ini_set( 'memory_limit', '512M' );
            $this->log_error( "Memory limit increased from {$current_limit} to 512M" );
        }

        // Increase max execution time
        @set_time_limit( 300 );
    }

    /**
     * Increase upload size limit
     */
    public function increase_upload_limit( $size ) {
        return 1024 * 1024 * 50; // 50MB
    }

    /**
     * Auto convert on upload
     */
    public function auto_convert_image( $upload ) {
        // Check if it's an image
        if ( ! in_array( $upload['type'], array( 'image/jpeg', 'image/png', 'image/gif' ) ) ) {
            return $upload;
        }

        $settings = get_option( $this->option_name, $this->get_default_settings() );

        // Check if auto convert is enabled
        if ( ! $settings['auto_convert'] ) {
            $this->log_error( "Auto convert disabled in settings" );
            return $upload;
        }

        $file_path = $upload['file'];

        // Check if file exists
        if ( ! file_exists( $file_path ) ) {
            $this->log_error( "File not found: {$file_path}" );
            return $upload;
        }

        // Get image dimensions
        $image_info = @getimagesize( $file_path );
        if ( $image_info ) {
            $width = $image_info[0];
            $height = $image_info[1];
            $file_size = filesize( $file_path );
            $this->log_error( "Processing image: {$width}x{$height}px, Size: " . round($file_size/1024, 2) . "KB" );
        }

        // Increase memory for large images
        $this->increase_memory_limit();

        // Try conversion
        $converted = $this->convert_image( $file_path, $settings );

        if ( $converted ) {
            $this->log_error( "Successfully converted: {$file_path}" );
            $upload = $converted;
        } else {
            $this->log_error( "Failed to convert: {$file_path}" );
        }

        return $upload;
    }

    /**
     * Check if AVIF is actually supported (not just library exists)
     */
    private function is_avif_supported() {
        static $avif_supported = null;

        if ( $avif_supported !== null ) {
            return $avif_supported;
        }

        // Check ImageMagick delegates
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $imagick = new Imagick();
                $formats = $imagick->queryFormats( 'AVIF' );
                $avif_supported = ! empty( $formats );
                $imagick->clear();
                $imagick->destroy();
                return $avif_supported;
            } catch ( Exception $e ) {
                $avif_supported = false;
                return false;
            }
        }

        $avif_supported = false;
        return false;
    }

    /**
     * Convert image to WebP or AVIF
     */
    private function convert_image( $file_path, $settings = null, $force_format = null ) {
        if ( ! $settings ) {
            $settings = get_option( $this->option_name, $this->get_default_settings() );
        }

        // Check for required extensions
        $has_imagick = extension_loaded( 'imagick' );
        $has_gd = extension_loaded( 'gd' );

        if ( ! $has_imagick && ! $has_gd ) {
            $this->log_error( "ERROR: Neither ImageMagick nor GD library available" );
            return false;
        }

        $this->log_error( "Using " . ( $has_imagick ? 'ImageMagick' : 'GD' ) . " for conversion" );

        // Auto-detect and fallback to WebP if AVIF not supported
        $target_format = $force_format ? $force_format : $settings['format'];
        if ( $target_format === 'avif' && ! $this->is_avif_supported() ) {
            $this->log_error( "AVIF not supported by server, falling back to WebP" );
            $target_format = 'webp';
        }

        // Increase memory limit
        $this->increase_memory_limit();

        // Get image editor
        $image_editor = wp_get_image_editor( $file_path );

        if ( is_wp_error( $image_editor ) ) {
            $error_message = $image_editor->get_error_message();
            $this->log_error( "WP Image Editor Error: {$error_message}" );

            // Fallback to direct ImageMagick or GD processing
            return $this->convert_with_fallback( $file_path, $settings, $target_format );
        }

        $file_info = pathinfo( $file_path );

        // Use detected format
        $new_file_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.' . $target_format;

        // Set quality
        $quality = intval( $settings['quality'] );
        $image_editor->set_quality( $quality );

        // Save in selected format
        $mime_type = 'image/' . $target_format;
        $saved_image = $image_editor->save( $new_file_path, $mime_type );

        if ( ! is_wp_error( $saved_image ) && file_exists( $saved_image['path'] ) ) {
            $original_size = filesize( $file_path );
            $new_size = filesize( $saved_image['path'] );
            $saved_percent = round( ( 1 - $new_size / $original_size ) * 100, 2 );

            $this->log_error( "Conversion successful! Saved {$saved_percent}% - Original: " . round($original_size/1024, 2) . "KB, New: " . round($new_size/1024, 2) . "KB" );

            $result = array(
                'file' => $saved_image['path'],
                'url' => str_replace( basename( $file_path ), basename( $saved_image['path'] ), wp_get_attachment_url( attachment_url_to_postid( $file_path ) ) ),
                            'type' => $mime_type
            );

            if ( ! $settings['keep_original'] ) {
                wp_delete_file( $file_path );
                $this->log_error( "Original file deleted: {$file_path}" );
            }

            return $result;
        }

        if ( is_wp_error( $saved_image ) ) {
            $this->log_error( "Save Error: " . $saved_image->get_error_message() );
        }

        return false;
    }

    /**
     * Fallback conversion using direct ImageMagick or GD
     */
    private function convert_with_fallback( $file_path, $settings, $force_format = null ) {
        $this->log_error( "Attempting fallback conversion method" );

        $file_info = pathinfo( $file_path );
        $target_format = $force_format ? $force_format : $settings['format'];

        // Auto-detect and fallback to WebP if AVIF not supported
        if ( $target_format === 'avif' && ! $this->is_avif_supported() ) {
            $this->log_error( "AVIF not supported in fallback, using WebP" );
            $target_format = 'webp';
        }

        $new_file_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.' . $target_format;
        $quality = intval( $settings['quality'] );

        // Try ImageMagick first
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $imagick = new Imagick( $file_path );
                $imagick->setImageFormat( $target_format );
                $imagick->setImageCompressionQuality( $quality );

                if ( $imagick->writeImage( $new_file_path ) ) {
                    $imagick->clear();
                    $imagick->destroy();

                    $this->log_error( "Fallback ImageMagick conversion successful" );

                    $result = array(
                        'file' => $new_file_path,
                        'url' => str_replace( basename( $file_path ), basename( $new_file_path ), wp_get_attachment_url( attachment_url_to_postid( $file_path ) ) ),
                                    'type' => 'image/' . $target_format
                    );

                    if ( ! $settings['keep_original'] ) {
                        wp_delete_file( $file_path );
                    }

                    return $result;
                }
            } catch ( Exception $e ) {
                $this->log_error( "ImageMagick fallback failed: " . $e->getMessage() );
            }
        }

        // Try GD as last resort (for WebP only)
        if ( extension_loaded( 'gd' ) && $target_format === 'webp' ) {
            try {
                $image_info = getimagesize( $file_path );
                $mime_type = $image_info['mime'];

                // Create image resource based on type
                switch ( $mime_type ) {
                    case 'image/jpeg':
                        $image = imagecreatefromjpeg( $file_path );
                        break;
                    case 'image/png':
                        $image = imagecreatefrompng( $file_path );
                        imagepalettetotruecolor( $image );
                        imagealphablending( $image, true );
                        imagesavealpha( $image, true );
                        break;
                    case 'image/gif':
                        $image = imagecreatefromgif( $file_path );
                        break;
                    default:
                        $this->log_error( "Unsupported image type: {$mime_type}" );
                        return false;
                }

                if ( $image && imagewebp( $image, $new_file_path, $quality ) ) {
                    imagedestroy( $image );

                    $this->log_error( "Fallback GD conversion successful" );

                    $result = array(
                        'file' => $new_file_path,
                        'url' => str_replace( basename( $file_path ), basename( $new_file_path ), wp_get_attachment_url( attachment_url_to_postid( $file_path ) ) ),
                                    'type' => 'image/webp'
                    );

                    if ( ! $settings['keep_original'] ) {
                        wp_delete_file( $file_path );
                    }

                    return $result;
                }
            } catch ( Exception $e ) {
                $this->log_error( "GD fallback failed: " . $e->getMessage() );
            }
        }

        $this->log_error( "All fallback methods failed" );
        return false;
    }

    /**
     * Get default settings
     */
    private function get_default_settings() {
        return array(
            'auto_convert' => true,
            'format' => 'webp',
            'quality' => 80,
            'keep_original' => false
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'LightPixel',
            'LightPixel',
            'manage_options',
            'lightpixel-optimizer',
            array( $this, 'main_page' ),
                      'dashicons-images-alt2',
                      65
        );

        add_submenu_page(
            'lightpixel-optimizer',
            'Bulk Optimize',
            'Bulk Optimize',
            'manage_options',
            'lightpixel-bulk',
            array( $this, 'bulk_page' )
        );

        add_submenu_page(
            'lightpixel-optimizer',
            'Settings',
            'Settings',
            'manage_options',
            'lightpixel-settings',
            array( $this, 'settings_page' )
        );

        add_submenu_page(
            'lightpixel-optimizer',
            'Error Log',
            'Error Log',
            'manage_options',
            'lightpixel-logs',
            array( $this, 'logs_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize_settings' ) );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // Handle auto_convert - check for '1' string or true boolean
        $sanitized['auto_convert'] = isset( $input['auto_convert'] ) && ( $input['auto_convert'] === '1' || $input['auto_convert'] === true || $input['auto_convert'] === 1 );

        // Handle format
        $sanitized['format'] = in_array( $input['format'], array( 'webp', 'avif' ) ) ? $input['format'] : 'webp';

        // Handle quality
        $quality = intval( $input['quality'] );
        $sanitized['quality'] = ( $quality >= 1 && $quality <= 100 ) ? $quality : 80;

        // Handle keep_original - check for '1' string or true boolean
        $sanitized['keep_original'] = isset( $input['keep_original'] ) && ( $input['keep_original'] === '1' || $input['keep_original'] === true || $input['keep_original'] === 1 );

        return $sanitized;
    }

    /**
     * Get SVG icons
     */
    private function get_svg_icon( $name, $size = 24, $color = 'currentColor' ) {
        $icons = array(
            'lightbulb' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 3.09 1.79 4.38 2.69 6.38.3.67.31 1.42.31 2.12h5.5c0-.7.01-1.45.31-2.12.9-2 2.69-3.29 2.69-6.38a6 6 0 0 0-6-6z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'chart' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18M7 16l4-4 4 4 6-6" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'check' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'sparkles' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'clock' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="' . $color . '" stroke-width="2"/><path d="M12 6v6l4 2" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/></svg>',

            'refresh' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M1 4v6h6M23 20v-6h-6" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'settings' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3" stroke="' . $color . '" stroke-width="2"/><path d="M12 1v3m0 16v3M4.22 4.22l2.12 2.12m11.32 11.32l2.12 2.12M1 12h3m16 0h3M4.22 19.78l2.12-2.12m11.32-11.32l2.12-2.12" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/></svg>',

            'folder' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'info' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="' . $color . '" stroke-width="2"/><path d="M12 16v-4m0-4h.01" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/></svg>',

            'rocket' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M9 11L4 16v5h5l5-5m4-9l1-1a2 2 0 013 3l-1 1m-6 2a5 5 0 005-5 7 7 0 00-7-7 5 5 0 00-5 5 7 7 0 007 7z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'bolt' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'image' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="' . $color . '" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="' . $color . '"/><path d="M21 15l-5-5L5 21" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'package' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'palette' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 2a10 10 0 0110 10c0 1.5-1 2-2 2h-2a2 2 0 00-2 2c0 1.5 1 2 1 3a1 1 0 01-1 1c-5.523 0-10-4.477-10-10S6.477 2 12 2z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7.5" cy="10.5" r=".5" fill="' . $color . '"/><circle cx="12" cy="7.5" r=".5" fill="' . $color . '"/><circle cx="16.5" cy="10.5" r=".5" fill="' . $color . '"/></svg>',

            'save' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 21v-8H7v8M7 3v5h8" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'upload' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'arrow-up' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 19V5m0 0l-7 7m7-7l7 7" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'arrow-down' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M12 5v14m0 0l7-7m-7 7l-7-7" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'repeat' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M17 1l4 4-4 4" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 13v2a4 4 0 01-4 4H3" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'file' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13 2v7h7" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'warning' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 9v4m0 4h.01" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/></svg>',

            'trash' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'cpu' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="' . $color . '" stroke-width="2"/><rect x="9" y="9" width="6" height="6" stroke="' . $color . '" stroke-width="2"/><path d="M9 1v3m6-3v3m-6 16v3m6-3v3M1 9h3m16 0h3M1 15h3m16 0h3" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/></svg>',

            'book' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M4 19.5A2.5 2.5 0 016.5 17H20" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

            'zap' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z" fill="' . $color . '"/></svg>',
        );

        return isset( $icons[$name] ) ? $icons[$name] : '';
    }

    /**
     * Main dashboard page
     */
    public function main_page() {
        global $wpdb;

        $total_images = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
        $webp_images = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/webp'" );
        $avif_images = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/avif'" );
        $other_images = $total_images - $webp_images - $avif_images;

        // Get PHP limits
        $memory_limit = ini_get( 'memory_limit' );
        $max_upload = ini_get( 'upload_max_filesize' );
        $max_post = ini_get( 'post_max_size' );
        $max_execution = ini_get( 'max_execution_time' );

        ?>
        <style>
        .lightpixel-wrapper {
            max-width: 1400px;
            margin: 20px 0;
        }
        .lightpixel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }
        .lightpixel-header h1 {
            margin: 0 0 10px 0;
            font-size: 36px;
            font-weight: 700;
            color: white;
        }
        .lightpixel-header p {
            margin: 0;
            font-size: 18px;
            opacity: 0.95;
            color: white;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin: 30px 0;
        }
        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-left: 5px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: inherit;
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        .stat-card.blue { border-left-color: #667eea; }
        .stat-card.green { border-left-color: #10b981; }
        .stat-card.purple { border-left-color: #8b5cf6; }
        .stat-card.orange { border-left-color: #f59e0b; }
        .stat-card h3 {
            margin: 0 0 15px 0;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.7;
        }
        .stat-card.blue h3 { color: #667eea; }
        .stat-card.green h3 { color: #10b981; }
        .stat-card.purple h3 { color: #8b5cf6; }
        .stat-card.orange h3 { color: #f59e0b; }
        .stat-value {
            font-size: 48px;
            font-weight: 800;
            margin: 0;
            color: #1e293b;
            line-height: 1;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-top: 8px;
        }
        .info-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .info-card h2 {
            margin: 0 0 20px 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .info-card h2::before {
            content: '';
            width: 4px;
            height: 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 2px;
        }
        .server-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .info-icon.success { background: #d1fae5; color: #10b981; }
        .info-icon.warning { background: #fef3c7; color: #f59e0b; }
        .info-icon.error { background: #fee2e2; color: #ef4444; }
        .info-content strong {
            display: block;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .info-content span {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }
        .action-buttons {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .btn-modern {
            padding: 16px 32px !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            font-size: 16px !important;
            border: none !important;
            cursor: pointer;
            transition: all 0.3s ease !important;
            display: inline-flex !important;
            align-items: center;
            gap: 8px;
            text-decoration: none !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
        }
        .btn-primary-modern {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: white !important;
        }
        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4) !important;
        }
        .btn-secondary-modern {
            background: white !important;
            color: #667eea !important;
            border: 2px solid #667eea !important;
        }
        .btn-secondary-modern:hover {
            background: #667eea !important;
            color: white !important;
            transform: translateY(-2px);
        }
        .how-it-works {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
        }
        .how-it-works ol {
            margin: 20px 0;
            padding-left: 20px;
        }
        .how-it-works li {
            padding: 12px 0;
            font-size: 15px;
            color: #475569;
            line-height: 1.7;
        }
        .how-it-works li strong {
            color: #1e293b;
            font-weight: 700;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .alert-warning p {
            margin: 8px 0;
            color: #92400e;
        }
        </style>

        <div class="lightpixel-wrapper">
        <div class="lightpixel-header">
        <h1 style="display: flex; align-items: center; gap: 12px;">
        <?php echo $this->get_svg_icon( 'lightbulb', 40, 'white' ); ?>
        LightPixel Image Optimizer
        </h1>
        <p>Professional Image Optimization for WordPress - Boost Performance & Save Bandwidth</p>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
        <div class="stat-card blue">
        <h3 style="display: flex; align-items: center; gap: 8px;">
        <?php echo $this->get_svg_icon( 'chart', 18, '#667eea' ); ?>
        Total Images
        </h3>
        <p class="stat-value"><?php echo esc_html( number_format( $total_images ) ); ?></p>
        <p class="stat-label">In Media Library</p>
        </div>

        <div class="stat-card green">
        <h3 style="display: flex; align-items: center; gap: 8px;">
        <?php echo $this->get_svg_icon( 'check', 18, '#10b981' ); ?>
        WebP Optimized
        </h3>
        <p class="stat-value"><?php echo esc_html( number_format( $webp_images ) ); ?></p>
        <p class="stat-label"><?php echo $total_images > 0 ? round(($webp_images / $total_images) * 100) : 0; ?>% Optimized</p>
        </div>

        <div class="stat-card purple">
        <h3 style="display: flex; align-items: center; gap: 8px;">
        <?php echo $this->get_svg_icon( 'sparkles', 18, '#8b5cf6' ); ?>
        AVIF Optimized
        </h3>
        <p class="stat-value"><?php echo esc_html( number_format( $avif_images ) ); ?></p>
        <p class="stat-label"><?php echo $total_images > 0 ? round(($avif_images / $total_images) * 100) : 0; ?>% Optimized</p>
        </div>

        <div class="stat-card orange">
        <h3 style="display: flex; align-items: center; gap: 8px;">
        <?php echo $this->get_svg_icon( 'clock', 18, '#f59e0b' ); ?>
        Pending
        </h3>
        <p class="stat-value"><?php echo esc_html( number_format( $other_images ) ); ?></p>
        <p class="stat-label">Need Optimization</p>
        </div>
        </div>

        <!-- Server Configuration -->
        <div class="info-card">
        <h2><?php echo $this->get_svg_icon( 'settings', 24, '#667eea' ); ?> Server Configuration</h2>
        <div class="server-info">
        <div class="info-item">
        <div class="info-icon <?php echo ( intval( $memory_limit ) >= 256 ) ? 'success' : 'warning'; ?>">
        <?php echo $this->get_svg_icon( 'cpu', 20, ( intval( $memory_limit ) >= 256 ) ? '#10b981' : '#f59e0b' ); ?>
        </div>
        <div class="info-content">
        <strong>PHP Memory</strong>
        <span><?php echo $memory_limit; ?></span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon success">
        <?php echo $this->get_svg_icon( 'upload', 20, '#10b981' ); ?>
        </div>
        <div class="info-content">
        <strong>Max Upload</strong>
        <span><?php echo $max_upload; ?></span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon success">
        <?php echo $this->get_svg_icon( 'package', 20, '#10b981' ); ?>
        </div>
        <div class="info-content">
        <strong>Max POST Size</strong>
        <span><?php echo $max_post; ?></span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon success">
        <?php echo $this->get_svg_icon( 'clock', 20, '#10b981' ); ?>
        </div>
        <div class="info-content">
        <strong>Execution Time</strong>
        <span><?php echo $max_execution; ?>s</span>
        </div>
        </div>
        </div>
        </div>

        <!-- Image Library Support -->
        <div class="info-card">
        <h2><?php echo $this->get_svg_icon( 'book', 24, '#667eea' ); ?> Image Library Support</h2>
        <div class="server-info">
        <div class="info-item">
        <div class="info-icon <?php echo extension_loaded('imagick') ? 'success' : 'error'; ?>">
        <?php echo extension_loaded('imagick') ? $this->get_svg_icon( 'check', 20, '#10b981' ) : $this->get_svg_icon( 'warning', 20, '#ef4444' ); ?>
        </div>
        <div class="info-content">
        <strong>ImageMagick</strong>
        <span><?php echo extension_loaded('imagick') ? 'Available' : 'Not Available'; ?></span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon <?php echo extension_loaded('gd') ? 'success' : 'error'; ?>">
        <?php echo extension_loaded('gd') ? $this->get_svg_icon( 'check', 20, '#10b981' ) : $this->get_svg_icon( 'warning', 20, '#ef4444' ); ?>
        </div>
        <div class="info-content">
        <strong>GD Library</strong>
        <span><?php echo extension_loaded('gd') ? 'Available' : 'Not Available'; ?></span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon success">
        <?php echo $this->get_svg_icon( 'check', 20, '#10b981' ); ?>
        </div>
        <div class="info-content">
        <strong>WebP Support</strong>
        <span>Full Support</span>
        </div>
        </div>

        <div class="info-item">
        <div class="info-icon <?php
        $avif_check = extension_loaded('imagick') ? $this->is_avif_supported() : false;
        echo $avif_check ? 'success' : 'warning';
        ?>">
        <?php echo $avif_check ? $this->get_svg_icon( 'check', 20, '#10b981' ) : $this->get_svg_icon( 'warning', 20, '#f59e0b' ); ?>
        </div>
        <div class="info-content">
        <strong>AVIF Support</strong>
        <span><?php
        if ( extension_loaded('imagick') ) {
            echo $avif_check ? 'Full Support' : 'Auto-Fallback to WebP';
        } else {
            echo 'Not Available';
        }
        ?></span>
        </div>
        </div>
        </div>
        </div>

        <?php if ( file_exists( $this->log_file ) ): ?>
        <div class="alert-warning">
        <p><strong><?php echo $this->get_svg_icon( 'file', 20, '#92400e' ); ?> Debug Log Available</strong></p>
        <p>Error logs are being tracked for debugging. <a href="<?php echo admin_url('admin.php?page=lightpixel-logs'); ?>" style="color: #92400e; font-weight: 600; text-decoration: underline;">View Logs →</a></p>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="info-card">
        <h2><?php echo $this->get_svg_icon( 'rocket', 24, '#667eea' ); ?> Quick Actions</h2>
        <div class="action-buttons">
        <a href="<?php echo admin_url('admin.php?page=lightpixel-bulk'); ?>" class="btn-modern btn-primary-modern">
        <?php echo $this->get_svg_icon( 'refresh', 20, 'white' ); ?> Bulk Optimize Images
        </a>
        <a href="<?php echo admin_url('admin.php?page=lightpixel-settings'); ?>" class="btn-modern btn-secondary-modern">
        <?php echo $this->get_svg_icon( 'settings', 20, '#667eea' ); ?> Settings
        </a>
        <a href="<?php echo admin_url('upload.php'); ?>" class="btn-modern btn-secondary-modern">
        <?php echo $this->get_svg_icon( 'folder', 20, '#667eea' ); ?> Media Library
        </a>
        </div>
        </div>

        <!-- How It Works -->
        <div class="info-card how-it-works">
        <h2><?php echo $this->get_svg_icon( 'info', 24, '#667eea' ); ?> How It Works</h2>
        <ol>
        <li><strong><?php echo $this->get_svg_icon( 'refresh', 16, '#667eea' ); ?> Automatic Conversion:</strong> When you upload images, they're automatically converted to WebP/AVIF format with optimized compression</li>
        <li><strong><?php echo $this->get_svg_icon( 'bolt', 16, '#667eea' ); ?> Bulk Optimization:</strong> Convert all existing images in your media library with a single click - perfect for large websites</li>
        <li><strong><?php echo $this->get_svg_icon( 'save', 16, '#667eea' ); ?> Space Savings:</strong> WebP and AVIF formats can reduce file size by 25-35% compared to JPG/PNG while maintaining quality</li>
        <li><strong><?php echo $this->get_svg_icon( 'image', 16, '#667eea' ); ?> Large Image Support:</strong> Handles large images (like 581x2560px) with automatic memory optimization and fallback mechanisms</li>
        <li><strong><?php echo $this->get_svg_icon( 'zap', 16, '#667eea' ); ?> Smart Fallback:</strong> If AVIF is not supported, automatically uses WebP - ensuring your images are always optimized</li>
        </ol>
        </div>
        </div>
        <?php
    }

    /**
     * Bulk optimization page
     */
    public function bulk_page() {
        ?>
        <style>
        .bulk-wrapper {
            max-width: 1000px;
            margin: 20px 0;
        }
        .bulk-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.3);
        }
        .bulk-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            color: white;
        }
        .bulk-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
            color: white;
        }
        .conversion-card {
            background: white;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .conversion-card h2 {
            margin: 0 0 25px 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .conversion-card h2::before {
            content: '';
            width: 4px;
            height: 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 2px;
        }
        .conversion-mode {
            margin-bottom: 20px;
        }
        .radio-card {
            display: block;
            padding: 20px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .radio-card:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .radio-card input[type="radio"]:checked + .radio-content {
            border-left: 4px solid #10b981;
            padding-left: 16px;
        }
        .radio-card input[type="radio"] {
            display: none;
        }
        .radio-content {
            padding-left: 20px;
            transition: all 0.3s ease;
        }
        .radio-card.active {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-color: #10b981;
        }
        .radio-title {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .radio-description {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .start-button {
            width: 100%;
            padding: 18px 32px !important;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: white !important;
            border: none !important;
            border-radius: 10px !important;
            font-size: 18px !important;
            font-weight: 700 !important;
            cursor: pointer;
            transition: all 0.3s ease !important;
            margin-top: 20px;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3) !important;
        }
        .start-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(16, 185, 129, 0.4) !important;
        }
        .start-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .progress-container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-top: 20px;
        }
        .progress-wrapper {
            background: #e2e8f0;
            height: 40px;
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar-modern {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 20px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            position: relative;
            overflow: hidden;
        }
        .progress-bar-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        .progress-status {
            margin-top: 16px;
            font-size: 15px;
            color: #64748b;
            text-align: center;
        }
        .progress-status strong {
            color: #1e293b;
        }
        .results-card {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 2px solid #10b981;
            padding: 25px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .results-card h3 {
            margin: 0 0 15px 0;
            color: #065f46;
            font-size: 20px;
            font-weight: 700;
        }
        .results-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .result-stat {
            background: white;
            padding: 15px;
            border-radius: 8px;
        }
        .result-stat strong {
            display: block;
            color: #64748b;
            font-size: 13px;
            margin-bottom: 5px;
        }
        .result-stat span {
            font-size: 24px;
            font-weight: 800;
            color: #10b981;
        }
        </style>

        <div class="bulk-wrapper">
        <div class="bulk-header">
        <h1 style="display: flex; align-items: center; gap: 12px;">
        <?php echo $this->get_svg_icon( 'bolt', 36, 'white' ); ?>
        Bulk Image Optimization
        </h1>
        <p>Convert multiple images at once with smart processing</p>
        </div>

        <div class="conversion-card">
        <h2><?php echo $this->get_svg_icon( 'settings', 24, '#10b981' ); ?> Select Conversion Mode</h2>

        <div class="conversion-mode">
        <label class="radio-card active" onclick="selectMode(this)">
        <input type="radio" name="conversion_mode" value="all" checked>
        <div class="radio-content">
        <div class="radio-title"><?php echo $this->get_svg_icon( 'refresh', 20, '#10b981' ); ?> Convert JPG/PNG/GIF to WebP/AVIF</div>
        <p class="radio-description">Convert all unoptimized images to your selected format - Best for first-time optimization</p>
        </div>
        </label>

        <label class="radio-card" onclick="selectMode(this)">
        <input type="radio" name="conversion_mode" value="webp_to_avif">
        <div class="radio-content">
        <div class="radio-title"><?php echo $this->get_svg_icon( 'arrow-up', 20, '#10b981' ); ?> Convert WebP to AVIF</div>
        <p class="radio-description">Upgrade existing WebP images to AVIF format for even better compression</p>
        </div>
        </label>

        <label class="radio-card" onclick="selectMode(this)">
        <input type="radio" name="conversion_mode" value="avif_to_webp">
        <div class="radio-content">
        <div class="radio-title"><?php echo $this->get_svg_icon( 'arrow-down', 20, '#10b981' ); ?> Convert AVIF to WebP</div>
        <p class="radio-description">Convert AVIF images back to WebP for wider browser compatibility</p>
        </div>
        </label>

        <label class="radio-card" onclick="selectMode(this)">
        <input type="radio" name="conversion_mode" value="reconvert_all">
        <div class="radio-content">
        <div class="radio-title"><?php echo $this->get_svg_icon( 'repeat', 20, '#10b981' ); ?> Reconvert All Optimized Images</div>
        <p class="radio-description">Re-process all WebP/AVIF images with current quality settings</p>
        </div>
        </label>
        </div>

        <button id="start-bulk-conversion" class="start-button">
        <?php echo $this->get_svg_icon( 'bolt', 24, 'white' ); ?> Start Bulk Conversion
        </button>

        <div id="conversion-progress" style="display: none;">
        <div class="progress-container">
        <div class="progress-wrapper">
        <div id="progress-bar" class="progress-bar-modern" style="width: 0%;">0%</div>
        </div>
        <p id="conversion-status" class="progress-status">Preparing conversion...</p>
        </div>
        </div>

        <div id="conversion-results" style="display: none;"></div>
        </div>
        </div>

        <script>
        function selectMode(card) {
            document.querySelectorAll('.radio-card').forEach(c => c.classList.remove('active'));
            card.classList.add('active');
        }

        jQuery(document).ready(function($) {
            $('#start-bulk-conversion').click(function() {
                var mode = $('input[name="conversion_mode"]:checked').val();
                var button = $(this);

                button.prop('disabled', true).text('🔄 Processing...');
                $('#conversion-progress').show();
                $('#conversion-results').hide();

                // Get images to convert
                $.post(ajaxurl, {
                    action: 'lightpixel_get_images',
                    mode: mode
                }, function(response) {
                    if (response.success) {
                        var images = response.data.images;
                        var total = images.length;
                        var converted = 0;
                        var failed = 0;

                        if (total === 0) {
                            $('#conversion-status').html('<strong>No images found to convert.</strong>');
                            button.prop('disabled', false).html('⚡ Start Bulk Conversion');
                            return;
                        }

                        $('#conversion-status').html('Converting <strong>' + total + '</strong> images...');

                        // Convert images one by one
                        var convertNext = function(index) {
                            if (index >= total) {
                                $('#conversion-status').html('✅ <strong>Conversion complete!</strong>');
                                $('#conversion-results').html(
                                    '<div class="results-card">' +
                                '<h3>✅ Conversion Complete!</h3>' +
                                '<div class="results-stats">' +
                                '<div class="result-stat"><strong>Successfully Converted</strong><span>' + converted + '</span></div>' +
                                '<div class="result-stat"><strong>Failed</strong><span>' + failed + '</span></div>' +
                                '</div>' +
                                '</div>'
                                ).show();
                                button.prop('disabled', false).html('⚡ Start Bulk Conversion');
                                return;
                            }

                            var image = images[index];
                            $('#conversion-status').html('Converting: <strong>' + image.title + '</strong> (' + (index + 1) + '/' + total + ')');

                            $.post(ajaxurl, {
                                action: 'lightpixel_convert_image',
                                attachment_id: image.id,
                                mode: mode
                            }, function(response) {
                                if (response.success) {
                                    converted++;
                                } else {
                                    failed++;
                                }

                                var progress = Math.round(((index + 1) / total) * 100);
                                $('#progress-bar').css('width', progress + '%').text(progress + '%');

                                convertNext(index + 1);
                            });
                        };

                        convertNext(0);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if ( isset( $_POST['lightpixel_settings_submit'] ) ) {
            check_admin_referer( $this->option_name . '_nonce' );
            $settings = $this->sanitize_settings( $_POST['lightpixel_settings'] );
            update_option( $this->option_name, $settings );
            echo '<div class="notice notice-success is-dismissible" style="margin: 20px 0; padding: 15px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981;"><p style="margin: 0; color: #065f46; font-weight: 600;">✅ Settings saved successfully!</p></div>';
        }

        $settings = get_option( $this->option_name, $this->get_default_settings() );
        $avif_supported = $this->is_avif_supported();
        ?>
        <style>
        .settings-wrapper {
            max-width: 900px;
            margin: 20px 0;
        }
        .settings-header {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
        }
        .settings-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            color: white;
        }
        .settings-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
            color: white;
        }
        .settings-card {
            background: white;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .setting-row {
            padding: 25px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .setting-row:last-child {
            border-bottom: none;
        }
        .setting-label {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
            display: block;
        }
        .setting-description {
            font-size: 14px;
            color: #64748b;
            margin: 8px 0 0 0;
            line-height: 1.6;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 34px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: #10b981;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        .radio-group {
            display: flex;
            gap: 15px;
            flex-direction: column;
        }
        .radio-option {
            display: flex;
            align-items: flex-start;
            padding: 18px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .radio-option:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .radio-option.selected {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #6366f1;
        }
        .radio-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .radio-option input[type="radio"] {
            margin: 4px 12px 0 0;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .radio-option.disabled input[type="radio"] {
            cursor: not-allowed;
        }
        .radio-label {
            flex: 1;
        }
        .radio-title {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }
        .radio-desc {
            font-size: 13px;
            color: #64748b;
        }
        .quality-input {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .quality-input input[type="number"] {
            width: 100px;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            color: #1e293b;
        }
        .quality-input input[type="number"]:focus {
            outline: none;
            border-color: #6366f1;
        }
        .quality-slider {
            flex: 1;
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
            outline: none;
            -webkit-appearance: none;
        }
        .quality-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #6366f1;
            cursor: pointer;
        }
        .quality-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #6366f1;
            cursor: pointer;
        }
        .save-button {
            width: 100%;
            padding: 18px 32px !important;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
            color: white !important;
            border: none !important;
            border-radius: 10px !important;
            font-size: 18px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3) !important;
            margin-top: 10px !important;
        }
        .save-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(99, 102, 241, 0.4) !important;
        }
        .warning-banner {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .warning-banner strong {
            color: #92400e;
            font-weight: 700;
        }
        .warning-banner p {
            margin: 8px 0;
            color: #78350f;
            line-height: 1.6;
        }
        </style>

        <div class="settings-wrapper">
        <div class="settings-header">
        <h1 style="display: flex; align-items: center; gap: 12px;">
        <?php echo $this->get_svg_icon( 'settings', 36, 'white' ); ?>
        Plugin Settings
        </h1>
        <p>Customize your image optimization preferences</p>
        </div>

        <?php if ( ! $avif_supported ): ?>
        <div class="warning-banner">
        <p><strong><?php echo $this->get_svg_icon( 'warning', 20, '#92400e' ); ?> AVIF Not Supported</strong></p>
        <p>Your server's ImageMagick doesn't have AVIF encoder support. The plugin will automatically use WebP format instead. WebP is widely supported and provides excellent compression.</p>
        <p>To enable AVIF support, contact your hosting provider to install ImageMagick with AVIF delegates.</p>
        </div>
        <?php endif; ?>

        <form method="post" action="" id="lightpixel-settings-form">
        <?php wp_nonce_field( $this->option_name . '_nonce' ); ?>

        <div class="settings-card">
        <div class="setting-row">
        <label class="setting-label"><?php echo $this->get_svg_icon( 'refresh', 18, '#667eea' ); ?> Auto Convert on Upload</label>
        <label class="toggle-switch">
        <input type="checkbox" name="lightpixel_settings[auto_convert]" value="1" <?php checked( $settings['auto_convert'], true ); ?> onchange="autoSaveSettings()">
        <span class="toggle-slider"></span>
        </label>
        <p class="setting-description">When enabled, images will be automatically converted during upload. <strong>Recommended for large images and automatic optimization!</strong></p>
        </div>

        <div class="setting-row">
        <label class="setting-label"><?php echo $this->get_svg_icon( 'package', 18, '#667eea' ); ?> Output Format</label>
        <div class="radio-group">
        <label class="radio-option <?php echo $settings['format'] === 'webp' ? 'selected' : ''; ?>" onclick="selectFormat(this, 'webp')">
        <input type="radio" name="lightpixel_settings[format]" value="webp" <?php checked( $settings['format'], 'webp' ); ?>>
        <div class="radio-label">
        <div class="radio-title"><?php echo $this->get_svg_icon( 'check', 18, '#10b981' ); ?> WebP (Recommended)</div>
        <div class="radio-desc">Excellent compression, universal browser support, works on all servers</div>
        </div>
        </label>

        <label class="radio-option <?php echo $settings['format'] === 'avif' ? 'selected' : ''; ?> <?php echo ! $avif_supported ? 'disabled' : ''; ?>" onclick="<?php echo $avif_supported ? "selectFormat(this, 'avif')" : 'return false;'; ?>">
        <input type="radio" name="lightpixel_settings[format]" value="avif" <?php checked( $settings['format'], 'avif' ); ?> <?php disabled( ! $avif_supported ); ?>>
        <div class="radio-label">
        <div class="radio-title">
        <?php echo $avif_supported ? $this->get_svg_icon( 'sparkles', 18, '#8b5cf6' ) : $this->get_svg_icon( 'warning', 18, '#ef4444' ); ?>
        <?php echo $avif_supported ? 'AVIF' : 'AVIF (Not Available)'; ?>
        </div>
        <div class="radio-desc">
        <?php if ( $avif_supported ): ?>
        Better compression than WebP, limited browser support
        <?php else: ?>
        Server doesn't support AVIF - will auto-fallback to WebP
        <?php endif; ?>
        </div>
        </div>
        </label>
        </div>
        <div id="format-save-notification" style="display: none; margin-top: 12px; padding: 12px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 8px; color: #065f46; font-size: 14px; font-weight: 600;">
        <?php echo $this->get_svg_icon( 'check', 16, '#065f46' ); ?> Settings saved automatically!
        </div>
        </div>

        <div class="setting-row">
        <label class="setting-label"><?php echo $this->get_svg_icon( 'palette', 18, '#667eea' ); ?> Image Quality</label>
        <div class="quality-input">
        <input type="number" name="lightpixel_settings[quality]" id="quality-number" value="<?php echo esc_attr( $settings['quality'] ); ?>" min="1" max="100" onchange="syncQualitySlider(this.value); autoSaveSettings();">
        <input type="range" id="quality-slider" min="1" max="100" value="<?php echo esc_attr( $settings['quality'] ); ?>" class="quality-slider" oninput="syncQualityNumber(this.value)" onchange="autoSaveSettings()">
        </div>
        <p class="setting-description">Quality level (1-100). <strong>Recommended: 75-85</strong> for best balance between quality and file size. For large images, try 80-85.</p>
        </div>

        <div class="setting-row">
        <label class="setting-label"><?php echo $this->get_svg_icon( 'save', 18, '#667eea' ); ?> Keep Original Files</label>
        <label class="toggle-switch">
        <input type="checkbox" name="lightpixel_settings[keep_original]" value="1" <?php checked( $settings['keep_original'], true ); ?> onchange="autoSaveSettings()">
        <span class="toggle-slider"></span>
        </label>
        <p class="setting-description">Keep original JPG/PNG/GIF files after conversion. If disabled, originals will be deleted to save disk space.</p>
        </div>
        </div>

        <button type="submit" name="lightpixel_settings_submit" class="save-button">
        <?php echo $this->get_svg_icon( 'save', 24, 'white' ); ?> Save Settings
        </button>
        </form>
        </div>

        <script>
        function selectFormat(element, format) {
            // Remove selected class from all options
            document.querySelectorAll('.radio-option').forEach(option => {
                option.classList.remove('selected');
            });

            // Add selected class to clicked option
            element.classList.add('selected');

            // Check the radio button
            element.querySelector('input[type="radio"]').checked = true;

            // Auto-save
            autoSaveSettings();
        }

        function syncQualitySlider(value) {
            document.getElementById('quality-slider').value = value;
        }

        function syncQualityNumber(value) {
            document.getElementById('quality-number').value = value;
        }

        function autoSaveSettings() {
            var form = document.getElementById('lightpixel-settings-form');

            // Create FormData and add nonce
            var formData = new FormData();
            formData.append('action', 'lightpixel_save_settings');
            formData.append('_wpnonce', '<?php echo wp_create_nonce( $this->option_name . '_nonce' ); ?>');

            // Get all form values manually
            var autoConvert = form.querySelector('input[name="lightpixel_settings[auto_convert]"]');
            formData.append('lightpixel_settings[auto_convert]', autoConvert && autoConvert.checked ? '1' : '0');

            var format = form.querySelector('input[name="lightpixel_settings[format]"]:checked');
            if (format) {
                formData.append('lightpixel_settings[format]', format.value);
            }

            var quality = form.querySelector('input[name="lightpixel_settings[quality]"]');
            if (quality) {
                formData.append('lightpixel_settings[quality]', quality.value);
            }

            var keepOriginal = form.querySelector('input[name="lightpixel_settings[keep_original]"]');
            formData.append('lightpixel_settings[keep_original]', keepOriginal && keepOriginal.checked ? '1' : '0');

            // Show saving indicator
            var notification = document.getElementById('format-save-notification');
            if (notification) {
                notification.style.display = 'flex';
                notification.style.alignItems = 'center';
                notification.style.gap = '8px';
                notification.innerHTML = '<?php echo $this->get_svg_icon( 'refresh', 16, '#065f46' ); ?> Saving...';
            }

            // Send AJAX request
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (notification) {
                        notification.innerHTML = '<?php echo $this->get_svg_icon( 'check', 16, '#065f46' ); ?> Settings saved automatically!';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 2000);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        </script>
        <?php
    }

    /**
     * Logs page
     */
    public function logs_page() {
        ?>
        <style>
        .logs-wrapper {
            max-width: 1200px;
            margin: 20px 0;
        }
        .logs-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(245, 158, 11, 0.3);
        }
        .logs-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            color: white;
        }
        .logs-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
            color: white;
        }
        .logs-card {
            background: white;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .logs-actions {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        .clear-logs-btn {
            padding: 12px 24px !important;
            background: #ef4444 !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }
        .clear-logs-btn:hover {
            background: #dc2626 !important;
            transform: translateY(-2px);
        }
        .log-viewer {
            background: #1e293b;
            color: #e2e8f0;
            padding: 25px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.8;
            max-height: 600px;
            overflow-y: auto;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.3);
        }
        .log-viewer::-webkit-scrollbar {
            width: 10px;
        }
        .log-viewer::-webkit-scrollbar-track {
            background: #0f172a;
            border-radius: 10px;
        }
        .log-viewer::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 10px;
        }
        .log-viewer::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }
        .log-line {
            padding: 4px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .log-line:last-child {
            border-bottom: none;
        }
        .log-timestamp {
            color: #f59e0b;
            font-weight: 600;
        }
        .log-error {
            color: #ef4444;
        }
        .log-success {
            color: #10b981;
        }
        .log-info {
            color: #60a5fa;
        }
        .no-logs {
            text-align: center;
            padding: 60px 20px;
        }
        .no-logs-icon {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .no-logs-icon svg {
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        }
        .no-logs h3 {
            color: #64748b;
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .no-logs p {
            color: #94a3b8;
            font-size: 15px;
        }
        </style>

        <div class="logs-wrapper">
        <div class="logs-header">
        <h1 style="display: flex; align-items: center; gap: 12px;">
        <?php echo $this->get_svg_icon( 'file', 36, 'white' ); ?>
        Debug & Error Logs
        </h1>
        <p>Track conversion processes and troubleshoot issues</p>
        </div>

        <div class="logs-card">
        <?php if ( file_exists( $this->log_file ) ): ?>
        <div class="logs-actions">
        <a href="<?php echo admin_url('admin.php?page=lightpixel-logs&clear=1'); ?>" class="clear-logs-btn" onclick="return confirm('Are you sure you want to clear all logs?');">
        <?php echo $this->get_svg_icon( 'trash', 20, 'white' ); ?> Clear Logs
        </a>
        </div>

        <?php
        if ( isset( $_GET['clear'] ) && $_GET['clear'] == '1' ) {
            wp_delete_file( $this->log_file );
            echo '<div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 20px; border-radius: 10px; margin-bottom: 20px;"><p style="margin: 0; color: #065f46; font-weight: 600;">' . $this->get_svg_icon( 'check', 20, '#065f46' ) . ' Logs cleared successfully!</p></div>';
            echo '<meta http-equiv="refresh" content="1;url=' . admin_url('admin.php?page=lightpixel-logs') . '">';
        } else {
            $log_content = file_get_contents( $this->log_file );
            $log_lines = array_reverse( explode( "\n", trim( $log_content ) ) );
            $recent_logs = array_slice( $log_lines, 0, 200 );
            ?>
            <h3 style="margin: 0 0 15px 0; font-size: 18px; font-weight: 700; color: #1e293b;">Recent Logs (Last 200 entries)</h3>
            <div class="log-viewer">
            <?php foreach ( $recent_logs as $line ):
            if ( empty( trim( $line ) ) ) continue;

            // Color code based on content
            $class = '';
            if ( stripos( $line, 'error' ) !== false || stripos( $line, 'failed' ) !== false ) {
                $class = 'log-error';
            } elseif ( stripos( $line, 'success' ) !== false || stripos( $line, 'converted' ) !== false ) {
                $class = 'log-success';
            } elseif ( stripos( $line, 'using' ) !== false || stripos( $line, 'processing' ) !== false ) {
                $class = 'log-info';
            }

            // Highlight timestamp
            $line = preg_replace( '/\[(.*?)\]/', '<span class="log-timestamp">[$1]</span>', $line );
            ?>
            <div class="log-line <?php echo $class; ?>"><?php echo $line; ?></div>
            <?php endforeach; ?>
            </div>
            <?php
        }
        ?>
        <?php else: ?>
        <div class="no-logs">
        <div class="no-logs-icon">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M9 12H15M9 16H15M17 21H7C5.89543 21 5 20.1046 5 19V5C5 3.89543 5.89543 3 7 3H12.5858C12.851 3 13.1054 3.10536 13.2929 3.29289L18.7071 8.70711C18.8946 8.89464 19 9.149 19 9.41421V19C19 20.1046 18.1046 21 17 21Z" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M13 3V7C13 8.10457 13.8954 9 15 9H19" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        </div>
        <h3>No logs available yet</h3>
        <p>Logs will appear here when images are processed. Upload an image to start tracking!</p>
        </div>
        <?php endif; ?>
        </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'lightpixel_nonce', 'nonce' );
        check_ajax_referer( $this->option_name . '_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $settings = $this->sanitize_settings( $_POST['lightpixel_settings'] );
        update_option( $this->option_name, $settings );

        wp_send_json_success( array( 'message' => 'Settings saved successfully!' ) );
    }

    /**
     * AJAX: Get images for bulk conversion
     */
    public function ajax_get_images() {
        global $wpdb;

        // Get conversion mode
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'all';

        $mime_types = array();

        if ( $mode === 'all' ) {
            // Convert JPG/PNG/GIF to WebP/AVIF
            $mime_types = array('image/jpeg', 'image/png', 'image/gif');
        } elseif ( $mode === 'webp_to_avif' ) {
            // Convert WebP to AVIF
            $mime_types = array('image/webp');
        } elseif ( $mode === 'avif_to_webp' ) {
            // Convert AVIF to WebP
            $mime_types = array('image/avif');
        } elseif ( $mode === 'reconvert_all' ) {
            // Reconvert all optimized images
            $mime_types = array('image/webp', 'image/avif');
        }

        $mime_list = "'" . implode("','", $mime_types) . "'";

        $images = $wpdb->get_results( "
        SELECT ID as id, post_title as title, guid as url, post_mime_type as mime_type
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
        AND post_mime_type IN ($mime_list)
        ORDER BY ID DESC
        " );

        wp_send_json_success( array( 'images' => $images, 'mode' => $mode ) );
    }

    /**
     * AJAX: Convert single image
     */
    public function ajax_convert_image() {
        $attachment_id = intval( $_POST['attachment_id'] );
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'all';

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_send_json_error( array( 'message' => 'File not found' ) );
        }

        $settings = get_option( $this->option_name, $this->get_default_settings() );

        // Determine target format based on mode
        $force_format = null;
        if ( $mode === 'webp_to_avif' ) {
            $force_format = 'avif';
        } elseif ( $mode === 'avif_to_webp' ) {
            $force_format = 'webp';
        } elseif ( $mode === 'reconvert_all' ) {
            // Get current format and convert to opposite
            $current_mime = get_post_mime_type( $attachment_id );
            $force_format = ( $current_mime === 'image/webp' ) ? 'avif' : 'webp';
        }

        // Increase memory limit for this operation
        $this->increase_memory_limit();

        $result = $this->convert_image( $file_path, $settings, $force_format );

        if ( $result ) {
            // Update attachment metadata
            update_attached_file( $attachment_id, $result['file'] );
            wp_update_post( array(
                'ID' => $attachment_id,
                'post_mime_type' => $result['type']
            ) );

            wp_send_json_success( array( 'message' => 'Converted successfully' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Conversion failed' ) );
        }
    }

    /**
     * Add bulk action
     */
    public function add_bulk_action( $actions ) {
        $actions['imagify_convert'] = '💡 LightPixel - Convert to WebP/AVIF';
        return $actions;
    }

    /**
     * Handle bulk action
     */
    public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
        if ( $action !== 'imagify_convert' ) {
            return $redirect_to;
        }

        $converted = 0;
        $settings = get_option( $this->option_name, $this->get_default_settings() );

        // Increase memory limit
        $this->increase_memory_limit();

        foreach ( $post_ids as $post_id ) {
            $file_path = get_attached_file( $post_id );

            if ( $file_path && file_exists( $file_path ) ) {
                $result = $this->convert_image( $file_path, $settings );

                if ( $result ) {
                    update_attached_file( $post_id, $result['file'] );
                    wp_update_post( array(
                        'ID' => $post_id,
                        'post_mime_type' => $result['type']
                    ) );
                    $converted++;
                }
            }
        }

        $redirect_to = add_query_arg( 'imagify_converted', $converted, $redirect_to );
        return $redirect_to;
    }

    /**
     * Bulk action notices
     */
    public function bulk_action_notices() {
        if ( ! empty( $_REQUEST['imagify_converted'] ) ) {
            $converted = intval( $_REQUEST['imagify_converted'] );
            printf( '<div class="notice notice-success is-dismissible"><p>✅ LightPixel: Successfully converted %d image(s)!</p></div>', $converted );
        }
    }

    /**
     * Add settings link
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url('admin.php?page=lightpixel-settings') . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

new LightPixel_Optimizer();
