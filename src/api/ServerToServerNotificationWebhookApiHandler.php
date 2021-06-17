<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;

class ServerToServerNotificationWebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    private $hermesEmitter;

    public function __construct(Emitter $hermesEmitter)
    {
        $this->hermesEmitter = $hermesEmitter;
    }

    public function params()
    {
        return [];
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        $request = $this->rawPayload();
        $notification = $this->validateInput(__DIR__ . '/server-to-server-notification.schema.json', $request);
        if ($notification->hasErrorResponse()) {
            return $notification->getErrorResponse();
        }
        $parsedNotification = $notification->getParsedObject();

        $executeAt = (float) (new DateTime('now + 1 minutes'))->getTimestamp();
        $this->hermesEmitter->emit(new HermesMessage('apple-server-to-server-notification', [
            'notification' => $parsedNotification,
        ], null, null, $executeAt), HermesMessage::PRIORITY_HIGH);

        $response = new JsonResponse([
            'status' => 'ok',
            'result' => 'Server-To-Server Notification acknowledged.',
        ]);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }
}
