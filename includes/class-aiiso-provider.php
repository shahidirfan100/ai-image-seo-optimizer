<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AIISO_Provider {
    private const OR_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const NV_URL = 'https://integrate.api.nvidia.com/v1/chat/completions';

    public static function generate( string $image_path, array $context = array() ) {
        $settings = AIISO_Settings::get_all();
        $provider = $settings['provider'];
        $order = self::provider_order( $provider, $settings['provider_strategy'] );
        $errors = array();

        foreach ( $order as $p ) {
            $result = self::try_provider( $p, $image_path, $context, $settings );
            if ( ! is_wp_error( $result ) ) { return $result; }
            $errors[] = strtoupper( $p ) . ': ' . $result->get_error_message();
        }
        return new WP_Error( 'aiiso_all_providers_failed', implode( ' | ', $errors ) ?: 'No AI provider is configured.' );
    }

    public static function test( string $provider, string $key_override = '', string $model_override = '' ) {
        if ( ! in_array( $provider, array( 'openrouter', 'nvidia' ), true ) ) {
            return new WP_Error( 'aiiso_invalid_provider', 'Choose OpenRouter or NVIDIA.' );
        }

        $keys   = '' !== trim( $key_override ) ? array( trim( $key_override ) ) : AIISO_Settings::keys( $provider );
        $models = '' !== trim( $model_override ) ? array( trim( $model_override ) ) : AIISO_Settings::models( $provider );
        if ( ! $keys ) {
            return new WP_Error( 'aiiso_missing_key', 'No saved API key was found for ' . ucfirst( $provider ) . '.' );
        }
        if ( ! $models ) {
            return new WP_Error( 'aiiso_missing_model', 'Add at least one model ID for ' . ucfirst( $provider ) . '.' );
        }

        $url   = 'openrouter' === $provider ? self::OR_URL : self::NV_URL;
        $key   = trim( $keys[0] );
        $model = trim( $models[0] );

        // A tiny real image is intentionally included so this validates the selected
        // model as a vision model instead of merely checking that text chat works.
        $test_image = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAoHBwgHBgoICAgLCgoLDhgQDg0NDh0VFhEYIx8lJCIfIiEmKzcvJik0KSEiMEExNDk7Pj4+JS5ESUM8SDc9Pjv/2wBDAQoLCw4NDhwQEBw7KCIoOzs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozv/wAARCABAAEADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/ALMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD/2Q==';
        $payload = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => 'Connection test. Inspect the attached image and reply with only OK.' ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => $test_image ) ),
                    ),
                ),
            ),
            'max_tokens' => 24,
            'stream'     => false,
        );

        $started = microtime( true );
        $resp = wp_remote_post( $url, array(
            'headers'     => self::headers( $provider, $key ),
            'body'        => wp_json_encode( $payload ),
            'timeout'     => 45,
            'redirection' => 3,
            'sslverify'   => true,
            'user-agent'  => 'AI-Image-SEO-Optimizer/' . ( defined( 'AIISO_VERSION' ) ? AIISO_VERSION : '1.0' ) . '; ' . home_url( '/' ),
        ) );
        $elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $resp ) ) {
            self::store_health( $provider, false, $model, 0, $elapsed_ms, $resp->get_error_message() );
            return new WP_Error( 'aiiso_network_test', 'Network request failed: ' . $resp->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );
        if ( 200 !== $code ) {
            $message = self::api_error_message( $json, $body );
            self::store_health( $provider, false, $model, $code, $elapsed_ms, $message );
            return new WP_Error( 'aiiso_api_test', strtoupper( $provider ) . ' returned HTTP ' . $code . ': ' . $message );
        }

        $content = self::response_content( is_array( $json ) ? $json : array() );
        if ( '' === trim( $content ) ) {
            self::store_health( $provider, false, $model, $code, $elapsed_ms, 'HTTP 200 but no completion text was returned.' );
            return new WP_Error( 'aiiso_empty_test', 'The provider returned HTTP 200, but the selected model returned no completion text.' );
        }

        self::store_health( $provider, true, $model, $code, $elapsed_ms, 'Live vision request succeeded.' );
        return array(
            'provider'   => $provider,
            'model'      => $model,
            'http_code'  => $code,
            'latency_ms' => $elapsed_ms,
            'content'    => mb_substr( trim( $content ), 0, 120 ),
        );
    }

    public static function health( string $provider ): array {
        $all = get_option( 'aiiso_provider_health', array() );
        return is_array( $all ) && isset( $all[ $provider ] ) && is_array( $all[ $provider ] ) ? $all[ $provider ] : array();
    }

    private static function store_health( string $provider, bool $ok, string $model, int $code, int $latency_ms, string $message ): void {
        $all = get_option( 'aiiso_provider_health', array() );
        if ( ! is_array( $all ) ) { $all = array(); }
        $all[ $provider ] = array(
            'ok'         => $ok ? 1 : 0,
            'model'      => sanitize_text_field( $model ),
            'http_code'  => $code,
            'latency_ms' => $latency_ms,
            'message'    => sanitize_text_field( mb_substr( $message, 0, 300 ) ),
            'tested_at'  => time(),
        );
        update_option( 'aiiso_provider_health', $all, false );
    }

    private static function response_content( array $json ): string {
        $content = $json['choices'][0]['message']['content'] ?? '';
        if ( is_string( $content ) ) { return $content; }
        if ( is_array( $content ) ) {
            $parts = array();
            foreach ( $content as $part ) {
                if ( is_string( $part ) ) { $parts[] = $part; }
                elseif ( is_array( $part ) && isset( $part['text'] ) ) { $parts[] = (string) $part['text']; }
            }
            return implode( ' ', $parts );
        }
        return '';
    }

    private static function api_error_message( $json, string $body ): string {
        if ( is_array( $json ) ) {
            $candidates = array(
                $json['error']['message'] ?? null,
                $json['message'] ?? null,
                $json['detail'] ?? null,
                $json['error'] ?? null,
            );
            foreach ( $candidates as $candidate ) {
                if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                    return mb_substr( wp_strip_all_tags( trim( $candidate ) ), 0, 300 );
                }
            }
        }
        $text = trim( wp_strip_all_tags( $body ) );
        return '' !== $text ? mb_substr( $text, 0, 300 ) : 'Unknown provider error.';
    }

    private static function provider_order( string $provider, string $strategy ): array {
        if ( 'openrouter' === $provider ) { return array( 'openrouter' ); }
        if ( 'nvidia' === $provider ) { return array( 'nvidia' ); }
        if ( 'round_robin' === $strategy ) {
            $i = (int) get_option( 'aiiso_provider_index', 0 );
            update_option( 'aiiso_provider_index', $i + 1, false );
            return 0 === $i % 2 ? array( 'openrouter', 'nvidia' ) : array( 'nvidia', 'openrouter' );
        }
        return array( 'openrouter', 'nvidia' );
    }

    private static function try_provider( string $provider, string $image_path, array $context, array $settings ) {
        $keys = AIISO_Settings::keys( $provider );
        $models = AIISO_Settings::models( $provider );
        if ( ! $keys || ! $models ) { return new WP_Error( 'aiiso_missing_config', 'No API key/model configured.' ); }

        $prepared = self::prepare_image( $image_path, (int) $settings['max_image_side'] );
        if ( is_wp_error( $prepared ) ) { return $prepared; }
        [ $bytes, $mime ] = $prepared;
        $data_url = 'data:' . $mime . ';base64,' . base64_encode( $bytes );
        $prompt = self::prompt( $context, $settings );
        $max_retries = max( 1, min( 8, (int) $settings['max_retries'] ) );
        $timeout = max( 20, min( 180, (int) $settings['request_timeout'] ) );
        $url = 'openrouter' === $provider ? self::OR_URL : self::NV_URL;
        $last_error = '';

        for ( $attempt = 0; $attempt < $max_retries; $attempt++ ) {
            $model = $models[ $attempt % count( $models ) ];
            $key   = $keys[ $attempt % count( $keys ) ];
            $payload = array(
                'model' => $model,
                'messages' => array( array(
                    'role' => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => $prompt ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
                    ),
                ) ),
                'temperature' => 0.2,
                'top_p' => 0.95,
                'max_tokens' => 1300,
                'stream' => false,
            );

            $resp = wp_remote_post( $url, array(
                'headers' => self::headers( $provider, $key ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => $timeout,
            ) );
            if ( is_wp_error( $resp ) ) {
                $last_error = $resp->get_error_message();
                usleep( 300000 * ( $attempt + 1 ) );
                continue;
            }
            $code = wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );
            if ( 200 === $code ) {
                $json = json_decode( $body, true );
                $content = self::response_content( is_array( $json ) ? $json : array() );
                $meta = self::parse( $content );
                if ( is_wp_error( $meta ) ) { $last_error = $meta->get_error_message(); continue; }
                $meta['_provider'] = $provider;
                $meta['_model'] = $model;
                $meta['_usage'] = $json['usage'] ?? array();
                return $meta;
            }
            $last_error = 'HTTP ' . $code . ': ' . substr( wp_strip_all_tags( $body ), 0, 260 );
            if ( 429 === $code || $code >= 500 ) {
                $wait = min( 8, 1 + ( 2 ** $attempt ) );
                if ( ! empty( $settings['low_429_mode'] ) ) { $wait += 1; }
                sleep( $wait );
                continue;
            }
            if ( in_array( $code, array( 401, 403 ), true ) && count( $keys ) > 1 ) { continue; }
            break;
        }
        return new WP_Error( 'aiiso_provider_failed', $last_error ?: 'Provider request failed.' );
    }

    private static function headers( string $provider, string $key ): array {
        $h = array( 'Authorization' => 'Bearer ' . trim( $key ), 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
        if ( 'openrouter' === $provider ) {
            $h['HTTP-Referer'] = home_url( '/' );
            $h['X-Title'] = 'AI Image SEO Optimizer';
        }
        return $h;
    }

    private static function prepare_image( string $path, int $max_side ) {
        if ( ! is_readable( $path ) ) { return new WP_Error( 'aiiso_image_missing', 'Image file is not readable.' ); }
        $mime = wp_check_filetype( basename( $path ) )['type'] ?? '';
        if ( ! $mime && function_exists( 'wp_get_image_mime' ) ) { $mime = (string) wp_get_image_mime( $path ); }
        if ( ! $mime || ! str_starts_with( $mime, 'image/' ) ) { return new WP_Error( 'aiiso_not_image', 'Unsupported image format.' ); }
        $size = filesize( $path );
        if ( $size && $size <= 1500000 ) { return array( file_get_contents( $path ), $mime ); }

        if ( ! function_exists( 'wp_get_image_editor' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
        $editor = wp_get_image_editor( $path );
        if ( ! is_wp_error( $editor ) ) {
            $dims = $editor->get_size();
            if ( $max_side > 0 && ( $dims['width'] > $max_side || $dims['height'] > $max_side ) ) { $editor->resize( $max_side, $max_side, false ); }
            $tmp = wp_tempnam( 'aiiso.jpg' );
            $saved = $editor->save( $tmp, 'image/jpeg' );
            if ( ! is_wp_error( $saved ) && is_readable( $saved['path'] ) ) {
                $bytes = file_get_contents( $saved['path'] );
                @unlink( $saved['path'] );
                return array( $bytes, 'image/jpeg' );
            }
        }
        return array( file_get_contents( $path ), $mime );
    }

    private static function prompt( array $context, array $s ): string {
        $ctx = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $language = 'site' === $s['language'] ? get_locale() : $s['language'];
        $custom = trim( (string) $s['custom_prompt'] );
        return "You are an expert WordPress image SEO and accessibility editor. Analyze the actual image first, then use page/product context only when it is relevant and visible/appropriate.\n\n"
            . "Return ONLY valid JSON with keys: alt, title, caption, description, filename, keywords.\n"
            . "alt: concise, natural, accessibility-first description; target {$s['alt_target']} characters where useful; never keyword-stuff; do not start with 'image of' or 'picture of'; omit decorative/irrelevant marketing language.\n"
            . "title: human-readable Media Library title, normally 4-12 words.\n"
            . "caption: optional useful visible caption, one short sentence; return empty string if a caption would add no value.\n"
            . "description: 1-2 useful sentences describing subject, setting, distinguishing details and relevant page/product context.\n"
            . "filename: short lowercase descriptive slug, 3-8 words joined by hyphens, no extension, no stop-word stuffing.\n"
            . "keywords: 8-18 highly relevant phrases; metadata aid only, not stuffing.\n"
            . "Use language/locale: {$language}. Preserve brand/product names and factual numbers when they are important. Never invent a brand, model, person, location, ingredient, material, certification, or product feature not supported by the image/context.\n"
            . "Context JSON: {$ctx}\n"
            . ( $custom ? "Additional site instruction: {$custom}\n" : '' );
    }

    private static function parse( string $text ) {
        $text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $text ) ) );
        $start = strpos( $text, '{' ); $end = strrpos( $text, '}' );
        if ( false !== $start && false !== $end && $end > $start ) { $text = substr( $text, $start, $end - $start + 1 ); }
        $data = json_decode( $text, true );
        if ( ! is_array( $data ) ) { return new WP_Error( 'aiiso_invalid_json', 'AI returned invalid JSON.' ); }
        $out = array();
        foreach ( array( 'alt','title','caption','description','filename' ) as $k ) { $out[ $k ] = sanitize_text_field( (string) ( $data[ $k ] ?? '' ) ); }
        $kw = $data['keywords'] ?? array();
        if ( is_string( $kw ) ) { $kw = preg_split( '/[,;]+/', $kw ); }
        $out['keywords'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $kw ) ? $kw : array() ) ) ) );
        $out['alt'] = self::clean_alt( $out['alt'] );
        $out['filename'] = sanitize_title( $out['filename'] );
        if ( '' === $out['alt'] || '' === $out['title'] ) { return new WP_Error( 'aiiso_incomplete', 'AI response did not contain usable ALT text/title.' ); }
        return $out;
    }

    private static function clean_alt( string $alt ): string {
        $alt = preg_replace( '/^(image|photo|picture|photograph)\s+of\s+/i', '', trim( $alt ) );
        return mb_substr( preg_replace( '/\s+/', ' ', $alt ), 0, 250 );
    }
}
