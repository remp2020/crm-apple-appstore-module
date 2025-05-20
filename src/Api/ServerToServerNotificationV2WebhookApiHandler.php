<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\AppleAppstoreModule\Models\Config;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Nette\Http\IResponse;
use Nette\Utils\DateTime;
use Readdle\AppStoreServerAPI\Exception\AppStoreServerNotificationException;
use Readdle\AppStoreServerAPI\ResponseBodyV2;
use Tomaj\Hermes\Emitter;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;

class ServerToServerNotificationV2WebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

    public function __construct(
        private readonly Emitter $hermesEmitter,
        private readonly ApplicationConfig $applicationConfig,
    ) {
        parent::__construct();
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $request = $this->rawPayload();
        $validationResult = $this->handleInputValidation($request);
        if ($validationResult instanceof JsonApiResponse) {
            return $validationResult;
        }

        try {
            ResponseBodyV2::createFromRawNotification(
                $request,
                $this->applicationConfig->get(Config::NOTIFICATION_CERTIFICATE),
            );
        } catch (AppStoreServerNotificationException $e) {
            exit('Server notification could not be processed: ' . $e->getMessage());
        }

        $executeAt = (float) (new DateTime('now + 1 minutes'))->getTimestamp();
        $this->hermesEmitter->emit(new HermesMessage(
            type: 'apple-server-to-server-notification-v2',
            payload: ['notification' => $request],
            executeAt: $executeAt,
        ), HermesMessage::PRIORITY_HIGH);

        return new JsonApiResponse(IResponse::S200_OK, [
            'status' => 'ok',
            'result' => 'Server-To-Server Notification acknowledged.',
        ]);
    }

    private function handleInputValidation($request): ?JsonApiResponse
    {
        $validation = $this->validateInput(__DIR__ . '/server-to-server-notification-v2.schema.json', $request);
        if ($validation->hasErrorResponse()) {
            $errorResponse = $validation->getErrorResponse();
            $errorPayload = $errorResponse->getPayload();
            if (isset($errorPayload['errors'])) {
                $details = 'Errors: [' . print_r($errorPayload['errors'], true) . ']';
            } else {
                $details = 'Request: [' . print_r($request, true) . ']';
            }
            Debugger::log(sprintf(
                "Unable to parse JSON of App Store Server Notifications V2: %s. %s",
                $errorPayload['message'],
                $details,
            ), Debugger::ERROR);
            return $validation->getErrorResponse();
        }

        return null;
    }
}
