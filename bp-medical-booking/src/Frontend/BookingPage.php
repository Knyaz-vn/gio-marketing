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
        $asset = static fn(string $path) => esc_url(Plugin::assetUrl($path));

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html('Онлайн-запис до лікаря — ' . $site); ?></title>
<meta name="description" content="<?php echo esc_attr('Онлайн-запис до лікарів ' . $site . ' у Вінниці: оберіть лікаря та вільний час, адміністратор зателефонує для підтвердження.'); ?>">
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700;800&amp;display=swap">
<link rel="stylesheet" href="<?php echo $asset('assets/page/page.css'); // phpcs:ignore ?>">
<script>window.BPMB_CONFIG=<?php echo wp_json_encode($this->front->widgetConfig()); ?>;</script>
<script src="<?php echo $asset('assets/widget/booking.js'); // phpcs:ignore ?>" defer></script>
</head>
<body class="bpp">
<?php if ($gtm !== '') : ?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php endif; ?>
<header class="bpp-head">
  <div class="bpp-container bpp-head-in">
    <a class="bpp-logo" href="<?php echo esc_url($home); ?>" aria-label="<?php echo esc_attr($site . ': на головну'); ?>">
      <?php if ($logo) : ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($site); ?>" width="72" height="72"><?php else : ?><span><?php echo esc_html($site); ?></span><?php endif; ?>
    </a>
    <div class="bpp-head-info">
      <?php foreach ($locations as $l) : ?>
      <div class="bpp-head-item">
        <?php echo self::icon('pin'); // phpcs:ignore ?>
        <div>
          <span><?php echo esc_html((string) ($l['address'] ?? '')); ?></span>
          <?php if (!empty($l['phone'])) : ?><a href="tel:<?php echo esc_attr(self::tel((string) $l['phone'])); ?>"><?php echo self::icon('phone'); // phpcs:ignore ?><?php echo esc_html((string) ($l['phone_display'] ?? $l['phone'])); ?></a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="bpp-head-item bpp-head-hours">
        <?php echo self::icon('clock'); // phpcs:ignore ?>
        <div><?php foreach ($this->hoursLines() as $line) : ?><span><?php echo esc_html($line); ?></span><?php endforeach; ?></div>
      </div>
    </div>
    <a class="bpp-head-phone" href="tel:<?php echo esc_attr(self::tel((string) $mainPhone)); ?>"><?php echo self::icon('phone'); // phpcs:ignore ?><span><?php echo esc_html((string) $mainPhoneText); ?></span></a>
  </div>
  <nav class="bpp-menu" aria-label="Меню">
    <div class="bpp-container">
      <a href="<?php echo esc_url($home); ?>">Головна</a>
      <a href="<?php echo esc_url(home_url('/doctors/')); ?>">Лікарі</a>
      <a class="bpp-menu-on" href="<?php echo esc_url($this->p->bookingPageUrl()); ?>" aria-current="page">Онлайн-запис</a>
    </div>
  </nav>
</header>
<main class="bpp-main" id="main">
  <div class="bpp-container">
    <h1>Онлайн-запис до лікаря</h1>
    <p class="bpp-lead">Оберіть лікаря та вільний час. Адміністратор клініки <b>BP MEDICAL</b> зателефонує вам, щоб підтвердити запис.</p>
    <span class="bpp-line" aria-hidden="true"></span>
    <div class="bp-booking-list"><p class="bpp-fallback">Завантажуємо вільний час лікарів… Якщо список не з’явився за кілька секунд, оновіть сторінку (Ctrl+Shift+R) або зателефонуйте <a href="tel:<?php echo esc_attr(self::tel((string) $mainPhone)); ?>"><?php echo esc_html((string) $mainPhoneText); ?></a>.</p></div>
    <noscript><p>Для онлайн-запису увімкніть JavaScript або зателефонуйте: <a href="tel:<?php echo esc_attr(self::tel((string) $mainPhone)); ?>"><?php echo esc_html((string) $mainPhoneText); ?></a>.</p></noscript>
  </div>
  <section class="bpp-container bpp-info" aria-label="Контакти клініки">
    <div class="bpp-info-col">
      <h2>Графік роботи</h2>
      <dl class="bpp-hours">
        <?php foreach ($this->hoursRows() as [$days, $time]) : ?><dt><?php echo esc_html($days); ?></dt><dd><?php echo esc_html($time); ?></dd><?php endforeach; ?>
      </dl>
    </div>
    <div class="bpp-info-col">
      <h2>Адреси</h2>
      <?php foreach ($locations as $l) : ?>
      <p class="bpp-addr"><b><?php echo esc_html((string) ($l['name'] ?? '')); ?></b><br><?php echo esc_html((string) ($l['address'] ?? '')); ?></p>
      <?php endforeach; ?>
    </div>
    <div class="bpp-info-col">
      <h2>Зателефонуйте нам</h2>
      <?php foreach ($locations as $l) : ?>
      <?php if (!empty($l['phone'])) : ?><p class="bpp-call"><a href="tel:<?php echo esc_attr(self::tel((string) $l['phone'])); ?>"><?php echo esc_html((string) ($l['phone_display'] ?? $l['phone'])); ?></a> <span><?php echo esc_html((string) ($l['name'] ?? '')); ?></span></p><?php endif; ?>
      <?php endforeach; ?>
      <p class="bpp-muted">Зателефонуйте та запишіться на прийом у зручний для вас час.</p>
    </div>
  </section>
</main>
<footer class="bpp-foot">
  <div class="bpp-container">
    <span>© <?php echo esc_html(gmdate('Y') . ' ' . $site); ?></span>
    <?php if ($privacy !== '') : ?><a href="<?php echo esc_url($privacy); ?>">Політика конфіденційності</a><?php endif; ?>
  </div>
</footer>
</body>
</html>
        <?php
    }

    private static function tel(string $phone): string
    {
        return (string) preg_replace('/[^\d+]/', '', $phone);
    }

    /** Іконки як на сайті клініки (контурні, червоні). */
    private static function icon(string $name): string
    {
        $paths = [
            'pin' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
            'phone' => '<path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 12l5 2v3a2 2 0 0 1-2 2A15 15 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        ];
        return '<svg class="bpp-ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
    }

    /** @return list<array{0:string,1:string}> [["Понеділок–п'ятниця", "9.00 – 18.00"], ...] */
    private function hoursRows(): array
    {
        $names = [1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа', 4 => 'Четвер', 5 => 'П’ятниця', 6 => 'Субота', 7 => 'Неділя'];
        $rows = [];
        foreach ($this->hourGroups() as $g) {
            $days = $names[$g['from']] . ($g['to'] !== $g['from'] ? '–' . mb_strtolower($names[$g['to']]) : '');
            $rows[] = [$days, $g['label'] === 'вихідний' ? 'вихідний' : str_replace(['–', ':'], [' – ', '.'], $g['label'])];
        }
        return $rows;
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
        return function_exists('get_site_icon_url') ? (string) get_site_icon_url(192) : '';
    }

    /** @return list<array{from:int,to:int,label:string}> дні з однаковими годинами клініки */
    private function hourGroups(): array
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
        return $groups;
    }

    /** ["Пн–Пт 09:00–18:00", "Сб 09:00–15:00", "Нд вихідний"]. @return string[] */
    private function hoursLines(): array
    {
        return array_map(static function (array $g): string {
            $days = self::WD[$g['from']] . ($g['to'] !== $g['from'] ? '–' . self::WD[$g['to']] : '');
            return $days . ' ' . $g['label'];
        }, $this->hourGroups());
    }
}
