<?php
/**
 * Settings → ScreamingRabbit: paste the connection code from the app, see the connection, or disconnect.
 * The secret is kept in wp_options without autoload and is never shown again after pairing.
 */
defined('ABSPATH') || exit;

class SR_Settings
{
    const OPTION = 'sr_connector';

    public static function secret()
    {
        $option = get_option(self::OPTION, []);

        return isset($option['secret']) ? (string) $option['secret'] : '';
    }

    /** Whether an administrator allowed ScreamingRabbit to apply approved changes (off by default). */
    public static function writes_enabled()
    {
        $option = get_option(self::OPTION, []);

        return self::secret() !== '' && ! empty($option['writes']);
    }

    public static function register()
    {
        add_options_page('ScreamingRabbit', 'ScreamingRabbit', 'manage_options', 'screamingrabbit', [__CLASS__, 'page']);
    }

    public static function page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $option = get_option(self::OPTION, []);
        $message = isset($_GET['sr_message']) ? sanitize_text_field(wp_unslash($_GET['sr_message'])) : '';
        ?>
		<div class="wrap">
			<h1>ScreamingRabbit bağlantısı</h1>
			<?php if ($message !== '') { ?>
				<div class="notice notice-info"><p><?php echo esc_html($message); ?></p></div>
			<?php } ?>

			<?php if (self::secret() !== '') { ?>
				<p><strong>Bağlı:</strong> <?php echo esc_html(isset($option['app']) ? $option['app'] : ''); ?>
					(<?php echo esc_html(isset($option['connected_at']) ? $option['connected_at'] : ''); ?>)</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('sr_disconnect'); ?>
					<input type="hidden" name="action" value="sr_disconnect">
					<?php submit_button('Bağlantıyı kes', 'delete'); ?>
				</form>

				<h2>Değişiklik izni</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('sr_writes'); ?>
					<input type="hidden" name="action" value="sr_writes">
					<label><input type="checkbox" name="sr_writes" value="1" <?php checked(self::writes_enabled()); ?>>
						ScreamingRabbit, panelde onaylanan değişiklikleri uygulayabilir</label>
					<p class="description">Yalnızca sayfaların SEO başlığı, meta açıklaması ve noindex ayarı (Yoast SEO veya Rank Math alanlarına).
						Her değişiklik panelde ayrıca onaylanır, geri alınabilir ve son 200 değişiklik bu sitede kayıtlı tutulur. Kapalıyken eklenti hiçbir şey değiştirmez.</p>
					<?php submit_button('Kaydet', 'secondary'); ?>
				</form>
			<?php } else { ?>
				<p>ScreamingRabbit panelinde projenin <em>WordPress</em> sayfasından aldığın bağlantı kodunu yapıştır.</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('sr_connect'); ?>
					<input type="hidden" name="action" value="sr_connect">
					<textarea name="sr_code" rows="3" class="large-text code" required></textarea>
					<?php submit_button('Bağlan'); ?>
				</form>
			<?php } ?>

			<h2>Bu eklenti ne paylaşır?</h2>
			<p>Yalnızca okur ve yalnızca bağlandığın ScreamingRabbit hesabına gönderir: WordPress, PHP ve MySQL sürümleri; eklenti ve
				temaların adı, sürümü, etkinliği ve güncelleme durumu; SEO ve güvenlikle ilgili ayarlar (arama motoru görünürlüğü,
				kalıcı bağlantılar, hata ayıklama, dosya düzenleyici, otomatik güncelleme, yönetici <em>sayısı</em>); içerik türlerine göre
				yazı sayıları; SEO eklentisinin içerik türü noindex ve site haritası ayarı; WordPress Site Sağlığı uyarıları; çekirdek
				dosyalarının wordpress.org sürümüyle aynı olup olmadığı (değişen dosyaların <em>yolu</em>); bir içerik yayınlandığında, güncellendiğinde veya yayından kaldırıldığında yalnızca o sayfanın
				adresi ve türü (hemen yeniden kontrol edilmesi için). Kullanıcı adı, e-posta, yorum, sipariş gibi kişisel veriler toplanmaz. Değişiklik izni kapalıyken siteye hiçbir
				değişiklik yapmaz; ziyaretçi sayfalarına kod eklemez.</p>
		</div>
		<?php
    }

    public static function connect()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Yetkiniz yok.');
        }
        check_admin_referer('sr_connect');

        $raw = isset($_POST['sr_code']) ? trim(sanitize_textarea_field(wp_unslash($_POST['sr_code']))) : '';
        $token = self::decode($raw);
        if ($token === null) {
            self::back('Bağlantı kodu okunamadı. Kodu panelden yeniden kopyalayın.');
        }

        $response = wp_remote_post(
            $token['u'].'/wp-baglanti/eslestir',
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode(
                    [
                        'code' => $token['c'],
                        'site_url' => home_url('/'),
                        'plugin_version' => SR_CONNECTOR_VERSION,
                    ]
                ),
            ]
        );
        if (is_wp_error($response)) {
            self::back('ScreamingRabbit\'e ulaşılamadı: '.$response->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || empty($body['secret'])) {
            self::back(isset($body['message']) ? (string) $body['message'] : 'Bağlantı kurulamadı (HTTP '.wp_remote_retrieve_response_code($response).').');
        }

        update_option(
            self::OPTION,
            [
                'secret' => (string) $body['secret'],
                'app' => $token['u'],
                'connected_at' => current_time('mysql'),
            ],
            false
        );
        self::back('Bağlantı kuruldu.');
    }

    public static function writes()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Yetkiniz yok.');
        }
        check_admin_referer('sr_writes');
        $option = get_option(self::OPTION, []);
        if (! is_array($option) || empty($option['secret'])) {
            self::back('Önce bağlantı kurun.');
        }
        $option['writes'] = ! empty($_POST['sr_writes']);
        update_option(self::OPTION, $option, false);
        self::back($option['writes'] ? 'Değişiklik izni açıldı.' : 'Değişiklik izni kapatıldı.');
    }

    public static function disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die('Yetkiniz yok.');
        }
        check_admin_referer('sr_disconnect');
        delete_option(self::OPTION);
        self::back('Bağlantı kesildi.');
    }

    /**
     * The code is "SR1." followed by base64url JSON {"u": app address, "c": one-time code}.
     *
     * @return array{u: string, c: string}|null
     */
    public static function decode($raw)
    {
        if (strpos($raw, 'SR1.') !== 0) {
            return null;
        }
        $json = base64_decode(strtr(substr($raw, 4), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || empty($data['u']) || empty($data['c']) || strpos((string) $data['u'], 'https://') !== 0) {
            return null;
        }

        return [
            'u' => untrailingslashit(esc_url_raw((string) $data['u'])),
            'c' => (string) $data['c'],
        ];
    }

    private static function back($message)
    {
        wp_safe_redirect(add_query_arg('sr_message', rawurlencode($message), admin_url('options-general.php?page=screamingrabbit')));
        exit;
    }
}
