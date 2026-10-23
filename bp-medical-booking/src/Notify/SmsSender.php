<?php
declare(strict_types=1);

namespace BPMedical\Booking\Notify;

interface SmsSender
{
    /**
     * @param string $e164 номер у форматі +380XXXXXXXXX
     * @return string ID повідомлення в SMS-провайдера
     * @throws \RuntimeException якщо провайдер не прийняв повідомлення
     */
    public function send(string $e164, string $text): string;
}
