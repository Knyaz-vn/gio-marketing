<?php
declare(strict_types=1);

namespace BPMedical\Booking\Admin;

/**
 * Ролі:
 *  - administrator: усе (bpmb_manage_bookings + bpmb_manage_settings);
 *  - bp_receptionist (Реєстратор): заявки, дзвінки, діагностика календаря, перечитати календар.
 */
final class Roles
{
    public const CAP_BOOKINGS = 'bpmb_manage_bookings';
    public const CAP_SETTINGS = 'bpmb_manage_settings';
    public const RECEPTIONIST = 'bp_receptionist';

    public static function install(): void
    {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_BOOKINGS);
            $admin->add_cap(self::CAP_SETTINGS);
        }
        if (!get_role(self::RECEPTIONIST)) {
            add_role(self::RECEPTIONIST, 'Реєстратор BP Medical', [
                'read' => true,
                self::CAP_BOOKINGS => true,
            ]);
        }
    }

    public static function uninstall(): void
    {
        remove_role(self::RECEPTIONIST);
        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(self::CAP_BOOKINGS);
            $admin->remove_cap(self::CAP_SETTINGS);
        }
    }
}
