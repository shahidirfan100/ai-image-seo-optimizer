<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Settings {
    const OPTION = 'aiiso_settings';

    public static function defaults(): array {
        return array(
            'provider'                => 'both',
            'provider_strategy'       => 'primary_failover',
            'openrouter_keys'         => '',
            'nvidia_keys'             => '',
            'openrouter_models'       => "google/gemma-4-26b-a4b-it:free\ngoogle/gemma-4-31b-it:free\nnvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free",
            'nvidia_models'           => "nvidia/llama-3.1-nemotron-nano-vl-8b-v1\nnvidia/nemotron-nano-12b-v2-vl",
            'auto_new_uploads'        => 1,
            'safe_rename_new_uploads' => 0,
            'overwrite_alt'           => 0,
            'overwrite_title'         => 0,
            'overwrite_caption'       => 0,
            'overwrite_description'   => 0,
            'generate_caption'        => 1,
            'generate_description'    => 1,
            'generate_keywords'       => 1,
            'sync_frontend_alt'       => 1,
            'use_page_context'        => 1,
            'use_woo_context'         => 1,
            'use_seo_keywords'        => 1,
            'language'                => 'site',
            'alt_target'              => '80-140',
            'max_image_side'          => 1024,
            'max_retries'             => 4,
            'request_timeout'         => 75,
            'low_429_mode'            => 1,
            'min_width'               => 80,
            'min_height'              => 80,
            'custom_prompt'           => '',
            'preserve_manual_data'    => 1,
            'store_backup'            => 1,
            'delete_data_uninstall'   => 0,
        );
    }

    public static function get_all(): array {
        $saved = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function get( string $key, $default = null ) {
        $all = self::get_all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    public static function save( array $input ) {
        $old = self::get_all();
        $clean = self::defaults();

        $clean['provider']          = in_array( $input['provider'] ?? '', array( 'openrouter', 'nvidia', 'both' ), true ) ? $input['provider'] : 'both';
        $clean['provider_strategy'] = in_array( $input['provider_strategy'] ?? '', array( 'primary_failover', 'round_robin' ), true ) ? $input['provider_strategy'] : 'primary_failover';

        foreach ( array( 'openrouter_models', 'nvidia_models', 'custom_prompt', 'language', 'alt_target' ) as $key ) {
            $clean[ $key ] = sanitize_textarea_field( wp_unslash( $input[ $key ] ?? $old[ $key ] ?? '' ) );
        }

        foreach ( array( 'max_image_side', 'max_retries', 'request_timeout', 'min_width', 'min_height' ) as $key ) {
            $clean[ $key ] = max( 0, absint( $input[ $key ] ?? $old[ $key ] ?? 0 ) );
        }

        $bools = array(
            'auto_new_uploads','safe_rename_new_uploads','overwrite_alt','overwrite_title','overwrite_caption',
            'overwrite_description','generate_caption','generate_description','generate_keywords','sync_frontend_alt',
            'use_page_context','use_woo_context','use_seo_keywords','low_429_mode','preserve_manual_data','store_backup','delete_data_uninstall'
        );
        foreach ( $bools as $key ) { $clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1; }

        // Blank key fields mean "keep the existing saved key(s)". Explicit clear checkboxes erase them.
        foreach ( array( 'openrouter_keys', 'nvidia_keys' ) as $key ) {
            $clear = ! empty( $input[ 'clear_' . $key ] );
            $raw   = trim( (string) wp_unslash( $input[ $key ] ?? '' ) );
            if ( $clear ) {
                $clean[ $key ] = '';
            } elseif ( '' !== $raw ) {
                $clean[ $key ] = self::encrypt( self::sanitize_keys( $raw ) );
            } else {
                $clean[ $key ] = $old[ $key ] ?? '';
            }
        }

        update_option( self::OPTION, $clean, false );

        // Verify that provider credentials/models can be read back immediately.
        foreach ( array( 'openrouter', 'nvidia' ) as $provider ) {
            $key_field   = $provider . '_keys';
            $model_field = $provider . '_models';

            $submitted_key = trim( (string) wp_unslash( $input[ $key_field ] ?? '' ) );
            if ( '' !== $submitted_key && empty( $input[ 'clear_' . $key_field ] ) ) {
                $expected = self::sanitize_keys( $submitted_key );
                $actual   = implode( "\n", self::keys( $provider ) );
                if ( $expected !== $actual ) {
                    return new WP_Error( 'aiiso_key_save_failed', ucfirst( $provider ) . ' API key could not be saved or decrypted. Please re-enter it.' );
                }
            }

            if ( array_key_exists( $model_field, $input ) ) {
                $expected_models = self::normalize_lines( sanitize_textarea_field( wp_unslash( (string) $input[ $model_field ] ) ) );
                $actual_models   = implode( "\n", self::models( $provider ) );
                if ( $expected_models !== $actual_models ) {
                    return new WP_Error( 'aiiso_model_save_failed', ucfirst( $provider ) . ' model list could not be saved.' );
                }
            }
        }

        return true;
    }

    public static function key_summary( string $provider ): array {
        $keys = self::keys( $provider );
        if ( ! $keys ) {
            return array( 'count' => 0, 'masked' => '' );
        }
        $last = end( $keys );
        $tail = strlen( $last ) > 6 ? substr( $last, -6 ) : $last;
        return array(
            'count'  => count( $keys ),
            'masked' => '••••••' . $tail,
        );
    }

    private static function normalize_lines( string $raw ): string {
        $items = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
        return implode( "\n", $items );
    }

    public static function keys( string $provider ): array {
        $field = 'openrouter' === $provider ? 'openrouter_keys' : 'nvidia_keys';
        $stored = (string) self::get( $field, '' );
        if ( '' === $stored ) { return array(); }
        $plain = self::decrypt( $stored );
        if ( '' === $plain ) { return array(); }
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $plain ) ) ) );
    }

    public static function models( string $provider ): array {
        $field = 'openrouter' === $provider ? 'openrouter_models' : 'nvidia_models';
        $raw = (string) self::get( $field, '' );
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
    }

    private static function sanitize_keys( string $raw ): string {
        $keys = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) ) );
        return implode( "\n", array_map( 'sanitize_text_field', $keys ) );
    }

    private static function crypto_key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|aiiso|api-keys', true );
    }

    public static function encrypt( string $plain ): string {
        if ( '' === $plain ) { return ''; }
        $key = self::crypto_key();
        if ( function_exists( 'openssl_encrypt' ) ) {
            try {
                $iv = random_bytes( 16 );
                $cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                if ( false !== $cipher ) { return 'v1:' . base64_encode( $iv . $cipher ); }
            } catch ( Throwable $e ) {}
        }
        return 'b64:' . base64_encode( $plain );
    }

    public static function decrypt( string $stored ): string {
        if ( str_starts_with( $stored, 'v1:' ) && function_exists( 'openssl_decrypt' ) ) {
            $raw = base64_decode( substr( $stored, 3 ), true );
            if ( false !== $raw && strlen( $raw ) > 16 ) {
                $iv = substr( $raw, 0, 16 );
                $cipher = substr( $raw, 16 );
                $plain = openssl_decrypt( $cipher, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv );
                return false === $plain ? '' : $plain;
            }
        }
        if ( str_starts_with( $stored, 'b64:' ) ) {
            $plain = base64_decode( substr( $stored, 4 ), true );
            return false === $plain ? '' : $plain;
        }
        // Backward compatibility if a future migration imports plain values.
        return $stored;
    }
}
