<?php

namespace YesWiki\Ferme\Service;

/** Greets the referent of a wiki just made, with its deadline when it has one. */
class WelcomeMailer
{
    public function __construct(
        private readonly FarmConfig $config,
        private readonly FarmMailer $mailer,
        private readonly WikiLifetime $lifetime
    ) {
    }

    /** @param array<string, mixed> $entry */
    public function send(array $entry, string $username, \DateTimeImmutable $today): string
    {
        $state = $this->lifetime->describe($entry, $today);
        $limited = $state !== null && $state['kind'] !== WikiLifetime::PERMANENT && $state['expiresAt'] !== '';

        return $this->mailer->send($entry, $this->template('yeswiki-farm-welcome-mail-subject', 'FERME_WELCOME_MAIL_SUBJECT'), $this->template('yeswiki-farm-welcome-mail-body', 'FERME_WELCOME_MAIL_BODY'), [
            'username' => $this->config->text('yeswiki-farm-password-WikiAdmin') !== '' ? '' : $username,
            'lifetime' => $limited ? $this->lifetime->label($state['kind']) : '',
            'expires' => $limited ? self::day($state['expiresAt']) : '',
            'daysLeft' => $limited ? (string)$state['daysLeft'] : '',
            'donateUrl' => $this->config->text('yeswiki-farm-donate-url'),
        ]);
    }

    /** The text an admin set for a mail, or the default one. */
    private function template(string $key, string $default): string
    {
        $text = $this->config->text($key);

        return $text !== '' ? str_replace('\\n', "\n", $text) : _t($default);
    }

    public static function day(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed ? $parsed->format('d/m/Y') : $date;
    }
}
