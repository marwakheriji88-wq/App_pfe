<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FlaskIntegrationService
{
    private $flaskEndpoint;
    private $httpClient;

    public function __construct(string $flaskEndpoint, HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->flaskEndpoint = $flaskEndpoint;
    }

    public function executeFlaskAPI(array $data): array
    {
        $response = $this->httpClient->request(
            'POST',
            $this->flaskEndpoint . '/predict/university-transition', // Replace with the appropriate endpoint on your Flask application
            [
                'json' => $data,
            ]
        );

        return $response->toArray();
    }
}
