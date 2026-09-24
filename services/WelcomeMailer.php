<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

/** Greets the referent of a wiki just made, with its deadline when it has one. */
class WelcomeMailer
{
    private $wiki;
    private $mailer;
    private $lifetime;

    public function __construct(Wiki $wiki, FarmMailer $mailer, WikiLifetime $lifetime)
    {
        $this->wiki = $wiki;
        $this->mailer = $mailer;
        $this->lifetime = $lifetime;
    }

    public function send(array $entry, string $username, \DateTimeImmutable $today): string
    {
        $state = $this->lifetime->describe($entry, $today);
        $limited = $state !== null && $state['kind'] !== WikiLifetime::PERMANENT && $state['expiresAt'] !== '';

        return $this->mailer->send($entry, $this->template('yeswiki-farm-welcome-mail-subject', 'FERME_WELCOME_MAIL_SUBJECT'), $this->template('yeswiki-farm-welcome-mail-body', 'FERME_WELCOME_MAIL_BODY'), [
            'username' => $this->farmSetsPassword() ? '' : $username,
            'lifetime' => $limited ? $this->lifetime->label($state['kind']) : '',
            'expires' => $limited ? $this->day($state['expiresAt']) : '',
            'daysLeft' => $limited ? (string)$state['daysLeft'] : '',
            'donateUrl' => trim((string)($this->wiki->config['yeswiki-farm-donate-url'] ?? '')),
        ]);
    }

    /** Whether the farm picks the admin password itself, so the referent has none to log in with. */
    private function farmSetsPassword(): bool
    {
        return trim((string)($this->wiki->config['yeswiki-farm-password-WikiAdmin'] ?? '')) !== '';
    }

    /** The text an admin set for the mail in the configuration, or the default one. */
    private function template(string $key, string $default): string
    {
        $text = trim((string)($this->wiki->config[$key] ?? ''));

        return $text !== '' ? str_replace('\\n', "\n", $text) : _t($default);
    }

    private function day(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed ? $parsed->format('d/m/Y') : $date;
    }
}
