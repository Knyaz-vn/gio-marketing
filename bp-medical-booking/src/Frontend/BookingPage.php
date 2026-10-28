<?php
declare(strict_types=1);

namespace BPMedical\Booking\Frontend;

use BPMedical\Booking\Plugin;

/**
 * Окрема сторінка запису (/zapys/) у стилі "кабінету запису": ліворуч меню й контакти,
 * праворуч фільтр і список лікарів з вільним часом. Рендериться без теми сайту, тож
 * стилі теми/Elementor не можуть зламати верстку. GTM підключається напряму (gtm_id).
 */
final class BookingPage
{
    private const WD = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Нд'];

    private Plugin $p;
    private Frontend $front;

    public function __construct(Plugin $p, Frontend $front)
    {
        $this->p = $p;
        $this->front = $front;
    }

    public function render(): void
    {
        $cfg = $this->p->config();
        $site = (string) get_bloginfo('name');
        $home = home_url('/');
        $logo = $this->logoUrl();
        $gtm = (string) $cfg->get('gtm_id');
        $privacy = (string) $cfg->get('privacy_url');
        $locations = array_values($this->p->catalog()->locations());
        $mainPhone = $locations[0]['phone'] ?? (string) $cfg->get('callback_phone');
        $mainPhoneText = $locations[0]['phone_display'] ?? $mainPhone;
        $icon = function_exists('get_site_icon_url') ? get_site_icon_url(64) : '';
        $ver = BPMB_VERSION;
        $asset = static fn(string $path) => esc_url(plugins_url($path, BPMB_FILE) . '?ver=' . $ver);

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html('Запис до лікаря онлайн — ' . $site); ?></title>
<meta name="description" content="<?php echo esc_attr('Онлайн-запис до лікарів ' . $site . ': оберіть лікаря та вільний час, адміністратор зателефонує для підтвердження.'); ?>">
<?php if ($cfg->get('test_mode')) : ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="canonical" href="<?php echo esc_url($this->p->bookingPageUrl()); ?>">
<?php if ($icon) : ?>
<link rel="icon" href="<?php echo esc_url($icon); ?>">
<?php endif; ?>
<?php if ($gtm !== '') : ?>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_js($gtm); ?>');</script>
<?php endif; ?>
<link rel="stylesheet" href="<?php echo $asset('assets/page/page.css'); // phpcs:ignore ?>">
<script>window.BPMB_CONFIG=<?php echo wp_json_encode($this->front->widgetConfig()); ?>;</script>
<script src="<?php echo $asset('assets/widget/booking.js'); // phpcs:ignore ?>" defer></script>
</head>
<body class="bpp">
<?php if ($gtm !== '') : ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php endif; ?>
<header class="bpp-head">
  <a class="bpp-brand" href="<?php echo esc_url($home); ?>">
    <?php if ($logo) : ?><img src="<?php echo esc_url($logo); ?>" alt="" height="44"><?php endif; ?>
    <span><?php echo esc_html($site); ?></span>
  </a>
  <div class="bpp-head-right">
    <a class="bpp-phone" href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', (string) $mainPhone)); ?>"><?php echo esc_html((string) $mainPhoneText); ?></a>
    <a class="bpp-back" href="<?php echo esc_url($home); ?>">На сайт</a>
  </div>
</header>
<div class="bpp-wrap">
  <aside class="bpp-side" aria-label="Меню і контакти">
    <nav class="bpp-nav" aria-label="Меню">
      <a class="bpp-nav-on" href="<?php echo esc_url($this->p->bookingPageUrl()); ?>" aria-current="page">Запис до лікаря</a>
      <a href="<?php echo esc_url(home_url('/doctors-2/')); ?>">Лікарі</a>
      <a href="<?php echo esc_url($home); ?>">Наш сайт</a>
    </nav>
    <div class="bpp-contacts">
      <?php foreach ($locations as $l) : ?>
      <div class="bpp-loc">
        <strong><?php echo esc_html((string) ($l['name'] ?? '')); ?></strong>
        <span><?php echo esc_html((string) preg_replace('/^м\.\s*Вінниця,\s*/u', '', (string) ($l['address'] ?? ''))); ?></span>
        <?php if (!empty($l['phone'])) : ?>
        <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', (string) $l['phone'])); ?>"><?php echo esc_html((string) ($l['phone_display'] ?? $l['phone'])); ?></a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <p class="bpp-hours"><?php foreach ($this->hoursLines() as $line) : ?><span><?php echo esc_html($line); ?></span><?php endforeach; ?></p>
    </div>
  </aside>
  <main class="bpp-main" id="main">
    <h1>Запис до лікаря онлайн</h1>
    <p class="bpp-lead">Оберіть лікаря і вільний час. Адміністратор зателефонує вам, щоб підтвердити запис.</p>
    <div class="bp-booking-list"></div>
    <noscript><p>Для онлайн-запису увімкніть JavaScript або зателефонуйте: <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', (string) $mainPhone)); ?>"><?php echo esc_html((string) $mainPhoneText); ?></a>.</p></noscript>
  </main>
</div>
<footer class="bpp-foot">
  <span>© <?php echo esc_html(gmdate('Y') . ' ' . $site); ?></span>
  <?php if ($privacy !== '') : ?><a href="<?php echo esc_url($privacy); ?>">Політика конфіденційності</a><?php endif; ?>
</footer>
</body>
</html>
        <?php
    }

    private function logoUrl(): string
    {
        $url = (string) $this->p->config()->get('logo_url');
        if ($url !== '') {
            return $url;
        }
        $id = (int) get_theme_mod('custom_logo');
        if ($id > 0) {
            $src = wp_get_attachment_image_url($id, 'medium');
            if ($src) {
                return (string) $src;
            }
        }
        return '';
    }

    /** ["Пн–Пт 09:00–18:00", "Сб 09:00–15:00", "Нд вихідний"] з годин клініки. @return string[] */
    private function hoursLines(): array
    {
        $cfg = $this->p->config();
        $groups = [];
        for ($wd = 1; $wd <= 7; $wd++) {
            $h = $cfg->clinicHours($wd);
            $label = $h ? $h[0] . '–' . $h[1] : 'вихідний';
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['label'] === $label) {
                $groups[$last]['to'] = $wd;
            } else {
                $groups[] = ['from' => $wd, 'to' => $wd, 'label' => $label];
            }
        }
        return array_map(static function (array $g): string {
            $days = self::WD[$g['from']] . ($g['to'] !== $g['from'] ? '–' . self::WD[$g['to']] : '');
            return $days . ' ' . $g['label'];
        }, $groups);
    }
}
