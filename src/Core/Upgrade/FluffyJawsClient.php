<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Adobe-internal FluffyJaws upgrade recommendation service.
 * This is the ONLY place in ACQC that calls https://fluffyjaws.adobe.com.
 */
class FluffyJawsClient implements UpgradeRecommendationClientInterface
{
    public function __construct(
        private string $baseUrl,
        private string $imsToken,
        private LoggerInterface $logger
    ) {}

    public function isConfigured(): bool
    {
        return $this->imsToken !== '';
    }

    public function recommend(string $findingType, array $context): ?array
    {
        $body = json_encode([
            'context'        => 'adobe-commerce-upgrade',
            'finding_type'   => $findingType,
            'source_version' => $context['source_version'] ?? '',
            'target_version' => $context['target_version'] ?? '',
            'payload'        => [
                'symbol'       => $context['symbol'] ?? null,
                'file_path'    => $context['file_path'] ?? null,
                'code_snippet' => substr($context['code_snippet'] ?? '', 0, 500),
            ],
        ]);

        $ch = curl_init($this->baseUrl . '/api/v1/recommendations');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->imsToken,
                'X-Adobe-Product: acqc',
                'Content-Type: application/json',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            $this->logger->warning('FluffyJaws network error', ['error' => $err, 'finding_type' => $findingType]);
            return null;
        }

        if ($code !== 200) {
            $this->logger->warning('FluffyJaws HTTP error', ['status' => $code, 'body' => substr((string)$raw, 0, 200)]);
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded) || !isset($decoded['recommendation'])) {
            $this->logger->warning('FluffyJaws unexpected response shape', ['body' => substr((string)$raw, 0, 200)]);
            return null;
        }

        return $decoded['recommendation'];
    }

    public static function fromEnv(LoggerInterface $logger): self
    {
        $token = $_ENV['ACQC_IMS_TOKEN'] ?? getenv('ACQC_IMS_TOKEN') ?: '';
        $url   = $_ENV['ACQC_FLUFFYJAWS_URL'] ?? getenv('ACQC_FLUFFYJAWS_URL') ?: 'https://fluffyjaws.adobe.com';

        return new self($url, (string)$token, $logger);
    }
}
