<?php

namespace App\Services;

use App\Helpers\StorageHelper;
use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Facades\Log;

class AwsFaceService
{
    private RekognitionClient $client;
    private string $collectionId;
    private float $defaultThreshold;

    public function __construct()
    {
        $this->client = new RekognitionClient([
            'version' => 'latest',
            'region' => config('services.aws.region'),
            'credentials' => [
                'key' => config('services.aws.key'),
                'secret' => config('services.aws.secret'),
            ],
        ]);

        $this->collectionId = config('services.aws.rekognition_collection_id');
        $this->defaultThreshold = (float) config('services.aws.rekognition_match_threshold');
    }

    public function createCollectionIfNotExists(): void
    {
        try {
            $this->client->createCollection([
                'CollectionId' => $this->collectionId,
            ]);
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                Log::error('AwsFaceService: failed to create collection', ['error' => $e->getMessage()]);
                throw $e;
            }
        }
    }

    public function indexFace(string $avatarRelativePath, int $studentId): ?string
    {
        $absolutePath = StorageHelper::getFullPath($avatarRelativePath);

        if (! $absolutePath || ! file_exists($absolutePath)) {
            Log::warning('AwsFaceService: avatar file not found for indexing', [
                'student_id' => $studentId,
                'path' => $avatarRelativePath,
            ]);

            return null;
        }

        try {
            $result = $this->client->indexFaces([
                'CollectionId' => $this->collectionId,
                'Image' => ['Bytes' => file_get_contents($absolutePath)],
                'ExternalImageId' => (string) $studentId,
                'MaxFaces' => 1,
                'QualityFilter' => 'AUTO',
                'DetectionAttributes' => [],
            ]);

            $faceRecords = $result->get('FaceRecords') ?? [];

            if (empty($faceRecords)) {
                Log::warning('AwsFaceService: no face detected in avatar', ['student_id' => $studentId]);

                return null;
            }

            return $faceRecords[0]['Face']['FaceId'] ?? null;
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'InvalidParameterException') {
                Log::warning('AwsFaceService: invalid image for indexing (no/blurry/multiple faces)', [
                    'student_id' => $studentId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            Log::error('AwsFaceService: indexFace failed', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteFace(string $faceId): void
    {
        try {
            $this->client->deleteFaces([
                'CollectionId' => $this->collectionId,
                'FaceIds' => [$faceId],
            ]);
        } catch (RekognitionException $e) {
            Log::error('AwsFaceService: deleteFace failed', ['face_id' => $faceId, 'error' => $e->getMessage()]);
        }
    }

    public function searchByImage(string $absoluteImagePath, int $maxFaces = 5, ?float $threshold = null): array
    {
        if (! file_exists($absoluteImagePath)) {
            throw new \RuntimeException('Image file not found');
        }

        $result = $this->client->searchFacesByImage([
            'CollectionId' => $this->collectionId,
            'Image' => ['Bytes' => file_get_contents($absoluteImagePath)],
            'MaxFaces' => $maxFaces,
            'FaceMatchThreshold' => $threshold ?? $this->defaultThreshold,
        ]);

        $matches = $result->get('FaceMatches') ?? [];

        $mapped = array_map(fn (array $match) => [
            'external_image_id' => $match['Face']['ExternalImageId'] ?? null,
            'similarity' => (float) ($match['Similarity'] ?? 0),
        ], $matches);

        $mapped = array_values(array_filter($mapped, fn (array $item) => $item['external_image_id'] !== null));

        usort($mapped, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return $mapped;
    }
}
