<?php

namespace App\Services\Outreach;

use App\Contracts\OutboundEmailGateway;
use App\Exceptions\OutreachException;
use App\Models\EmailProviderConnection;
use App\Models\OutreachMessage;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class SmtpEmailGateway implements OutboundEmailGateway
{
    public function __construct(private readonly MailManager $mail) {}

    public function verify(EmailProviderConnection $connection): array
    {
        $transport = $this->mailer($connection)->getSymfonyTransport();
        if (! $transport instanceof SmtpTransport) {
            throw new OutreachException('UNSUPPORTED_EMAIL_PROVIDER', 'Only configured SMTP delivery is available in this environment.', 409);
        }
        try {
            $transport->start();
            $transport->stop();

            return ['success' => true, 'message' => 'SMTP credentials were accepted.'];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'The SMTP provider could not be verified.'];
        }
    }

    public function send(EmailProviderConnection $connection, OutreachMessage $message): ProviderSendResult
    {
        $body = $message->body_html ?: nl2br(e($message->body_text));
        $sent = $this->mailer($connection)->html($body, function (Message $mail) use ($message): void {
            $mail->to($message->to_email, $message->to_name)
                ->from($message->from_email, $message->from_name)
                ->subject($message->subject);
            if ($message->reply_to_email) {
                $mail->replyTo($message->reply_to_email);
            }
            $mail->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $message->stable_message_id);
        });

        return new ProviderSendResult($sent?->getSymfonySentMessage()->getMessageId() ?? $message->stable_message_id);
    }

    public function webhookIsAuthentic(EmailProviderConnection $connection, Request $request): bool
    {
        return false;
    }

    public function normalizeWebhook(EmailProviderConnection $connection, array $payload): array
    {
        throw new OutreachException('PROVIDER_WEBHOOK_UNSUPPORTED', 'This provider does not support delivery webhooks.', 409);
    }

    public function synchronizeReplies(EmailProviderConnection $connection): array
    {
        throw new OutreachException('REPLY_SYNC_UNSUPPORTED', 'This provider does not support reply synchronization.', 409);
    }

    private function mailer(EmailProviderConnection $connection): Mailer
    {
        if ($connection->provider_type !== 'SMTP') {
            throw new OutreachException('PROVIDER_NOT_CONFIGURED', 'The selected OAuth provider adapter is not configured.', 409);
        }
        $configuration = $connection->configuration ?? [];
        $credentials = $connection->credentials ?? [];
        if (! isset($configuration['host'], $configuration['port'])) {
            throw new OutreachException('INVALID_PROVIDER_CONFIGURATION', 'SMTP host and port are required.');
        }

        return $this->mail->build([
            'name' => 'outreach-'.$connection->id,
            'transport' => 'smtp',
            'host' => $configuration['host'],
            'port' => (int) $configuration['port'],
            'scheme' => ($configuration['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'username' => $configuration['username'] ?? null,
            'password' => $credentials['password'] ?? null,
            'timeout' => 10,
        ]);
    }
}
