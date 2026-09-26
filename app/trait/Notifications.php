<?php

namespace App\trait;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;

trait Notifications
{
    protected $messaging;

    public function sendNotificationToMany(
        array $tokens,
        string $title,
        string $body,
        array $data = []
    ): ?MulticastSendReport {
        $tokens = array_filter($tokens);
        if (count($tokens) > 0) {
            $factory = (new Factory)->withServiceAccount(config('services.firebase.credentials'));
            $this->messaging = $factory->createMessaging();

            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data)
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ])
                );

            return $this->messaging->sendMulticast($message, $tokens);
        }

        return null; // مسموح دلوقتي
    }
}
