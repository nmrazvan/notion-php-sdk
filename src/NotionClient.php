<?php

namespace Notion;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Arr;
use Notion\Blocks\BasicBlock;
use Notion\Blocks\BlockInterface;
use Notion\Blocks\CollectionBlock;
use Psr\SimpleCache\CacheInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class NotionClient
{
    /**
     * @var string
     */
    protected $token;

    /**
     * @var Client
     */
    protected $client;
    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var Space
     */
    protected $currentSpace;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->cache = new FilesystemAdapter();
        $this->client = new Client([
            'base_uri' => getenv('API_BASE_URL'),
            'cookies' => CookieJar::fromArray(
                [
                    'token_v2' => getenv('NOTION_TOKEN'),
                ],
                'www.notion.so'
            ),
        ]);

        $this->loadUserInformations();
    }

    public function getBlock(string $identifier): BlockInterface
    {
        $blockId = Identifier::fromString($identifier);
        $attributes = $this->loadPageChunk($blockId);

        $block = (new BasicBlock($blockId, $attributes))->toTypedBlock();
        $block->setClient($this);

        return $block;
    }

    public function getCollection(string $identifier): CollectionBlock
    {
        $collectionId = Identifier::fromString($identifier);
        $attributes = $this->getRecordValues(new RecordRequest('collection', $collectionId))['value'];

        $collection = new CollectionBlock($collectionId, []);
        $collection->setAttributes($attributes);
        $collection->setClient($this);

        return $collection;
    }

    private function loadPageChunk(UuidInterface $blockId): array
    {
        $response = $this->cachedJsonRequest(
            'block-'.$blockId->toString(),
            'loadPageChunk',
            [
                'pageId' => $blockId->toString(),
                'limit' => 50,
                'cursor' => ['stack' => []],
                'chunkNumber' => 0,
                'verticalColumns' => false,
            ]);

        return $response['recordMap'] ?? [];
    }

    private function getRecordValues(RecordRequest $request): ?array
    {
        return $this->getRecordsValues([$request])[$request->getId()->toString()] ?? null;
    }

    /**
     * @param RecordRequest[] $requests
     */
    private function getRecordsValues(array $requests): array
    {
        $requests = collect($requests);
        $response = $this->cachedJsonRequest(sha1($requests->toJson()), 'getRecordValues', ['requests' => $requests->toArray()]);

        $results = $requests->mapWithKeys(function (RecordRequest $request, $key) use ($response) {
            $id = $request->getId()->toString();

            return [$id => $response['results'][$key] ?? []];
        });

        return $results->toArray();
    }

    public function getByParent(UuidInterface $getId, string $query = '')
    {
        $response = $this->cachedJsonRequest(
            'by-parent-'.$getId->toString(),
            'searchPagesWithParent', [
                'query' => $query,
                'parentId' => $getId->toString(),
                'limit' => 10000,
                'spaceId' => $this->getCurrentSpace()->getId()->toString(),
            ]);

        return $response['recordMap'] ?? [];
    }

    private function getCurrentSpace(): Space
    {
        return $this->currentSpace;
    }

    private function loadUserInformations(): void
    {
        $response = $this->cachedJsonRequest('user-informations', 'loadUserContent');

        $currentSpace = $response['recordMap']['space'];
        $currentSpace = Arr::first($currentSpace)['value'];
        $this->currentSpace = new Space(Identifier::fromString($currentSpace['id']), $currentSpace);
    }

    private function cachedJsonRequest(string $key, string $url, array $body = [])
    {
        return $this->cache->get(
            $key,
            function () use ($url, $body) {
                $response = $this->client->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=utf-8',
                    ],
                    'body' => $body === [] ? '{}' : json_encode(
                        $body
                    ),
                ]);

                $response = $response->getBody()->getContents();

                return json_decode($response, true);
            }
        );
    }
}