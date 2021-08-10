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
use Tracy\Debugger;

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
        $validation = $this->validateInput(__DIR__ . '/server-to-server-notification.schema.json', $request);
        if ($validation->hasErrorResponse()) {
            $errorResponse = $validation->getErrorResponse();
            $errorPayload = $errorResponse->getPayload();
            Debugger::log(
                "Unable to parse JSON of Apple's ServerToServerNotification. " . $errorPayload['message'] . ". Errors: [" . print_r($errorPayload['errors'], true) . '].',
                Debugger::ERROR
            );
            return $validation->getErrorResponse();
        }
        $parsedNotification = $validation->getParsedObject();

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
