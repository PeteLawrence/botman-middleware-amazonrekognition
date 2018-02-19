<?php

namespace PeteLawrence\BotMan\Middleware;

use BotMan\BotMan\BotMan;
use BotMan\BotMan\Http\Curl;
use BotMan\BotMan\Interfaces\HttpInterface;
use BotMan\BotMan\Interfaces\MiddlewareInterface;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use Aws\Rekognition\RekognitionClient;

class AmazonRekognition implements MiddlewareInterface {

    private $rekognition;

    public function __construct($region)
    {
        $this->rekognition = RekognitionClient::factory(
            [
                'region' => $region,
                'version' => 'latest'
            ]
        );
    }


    /**
     * Create a new Wit middleware instance.
     * @param string $token wit.ai access token
     * @return AmazonRekognition
     */
    public static function create($token)
    {
        return new static($token, new Curl());
    }

    /**
     * Handle a captured message.
     *
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @param BotMan $bot
     * @param $next
     *
     * @return mixed
     */
    public function captured(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }


    /**
     * Handle an incoming message.
     *
     * @param IncomingMessage $message
     * @param BotMan $bot
     * @param $next
     *
     * @return mixed
     */
    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        // Check for an attached image
        $images = $message->getImages();

        $rekognitionResults = [];

        if (sizeof($images) > 0) {
            // Extract the raw image data from the URL of the image
            $im = $this->getImageDataFromImageUrl($images[0]->getUrl());

            // Send the text to Amazon Lex for processing
            $result = $this->rekognition->detectLabels([
                'Image' => [
                    'Bytes' => $im
                ]
            ]);

            // Add the results to our results array
            $rekognitionResults[] = [
                'labels' => $result->get('Labels'),
                'orientationCorrection' => $result->get('OrientationCorrection')
            ];

            //TODO Add the results to the corresponding image. Waiting on outome of https://github.com/botman/botman/issues/718
        }

        // Add the results to the message
        $message->addExtras('rekognitionResults', $rekognitionResults);


        return $next($message);
    }


    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @param string $pattern
     * @param bool $regexMatched Indicator if the regular expression was matched too
     * @return bool
     */
    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        $pattern = '/^'.$pattern.'$/i';

        return (bool) preg_match($pattern, $message->getExtras()['lexIntent']);
    }


    /**
     * Handle a message that was successfully heard, but not processed yet.
     *
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @param BotMan $bot
     * @param $next
     *
     * @return mixed
     */
    public function heard(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }


    /**
     * Handle an outgoing message payload before/after it
     * hits the message service.
     *
     * @param mixed $payload
     * @param BotMan $bot
     * @param $next
     *
     * @return mixed
     */
    public function sending($payload, $next, BotMan $bot)
    {
        return $next($payload);
    }


    /**
     * Extracts the raw image data from the image URL
     * @param  string $payload The output from calling getUrl() on a Botman image
     * @return string          The raw image data
     */
    private function getImageDataFromImageUrl($payload)
    {
        // Payload looks like: data: image/jpeg;base64,ABC...XYZ

        // Remove 'data: '
        $payload = substr($payload, 6);

        // Extract mimetype
        $semiColonPosition = strpos($payload, ';');
        $mimeType = substr($payload, 0, $semiColonPosition);

        // Extract data
        $data = substr($payload, $semiColonPosition + 8);

        return base64_decode($data);
    }

}
