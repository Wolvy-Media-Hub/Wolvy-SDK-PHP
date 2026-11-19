<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Folder;

/**
 * Folders, nested up to 5 levels. Reads need `videos:read`, writes `videos:write`.
 */
class FoldersService extends AbstractService
{
    /**
     * GET /v1/folders: one page, newest first. Without `parent_id`, every folder at every level.
     *
     * @param array<string,mixed> $params limit, starting_after, parent_id (an id or "root"), q
     * @param array<string,mixed> $opts
     * @return Collection<Folder>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/folders', $params, $opts);
    }

    /**
     * Every folder matching the filters, across all pages.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,Folder>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/folders', $params, $opts);
    }

    /**
     * POST /v1/folders.
     *
     * @param array<string,mixed> $params name (required, 1-255), parent_id (null = top level)
     * @param array<string,mixed> $opts
     */
    public function create(array $params, array $opts = []): Folder
    {
        return self::cast($this->requestWrite('POST', '/folders', $params, $opts), Folder::class);
    }

    /**
     * GET /v1/folders/{id}.
     *
     * @param int|string          $id
     * @param array<string,mixed> $opts
     */
    public function retrieve($id, array $opts = []): Folder
    {
        return self::cast($this->requestGet('/folders/' . self::integer($id, 'folder id'), [], $opts), Folder::class);
    }

    /**
     * PATCH /v1/folders/{id}: rename and/or move (parent_id null = top level).
     *
     * @param int|string          $id
     * @param array<string,mixed> $params name, parent_id
     * @param array<string,mixed> $opts
     */
    public function update($id, array $params, array $opts = []): Folder
    {
        return self::cast($this->requestWrite('PATCH', '/folders/' . self::integer($id, 'folder id'), $params, $opts), Folder::class);
    }

    /**
     * DELETE /v1/folders/{id}. Nothing inside is deleted: its videos and sub-folders move up a level.
     *
     * @param int|string          $id
     * @param array<string,mixed> $opts
     */
    public function delete($id, array $opts = []): void
    {
        $this->requestDelete('/folders/' . self::integer($id, 'folder id'), $opts);
    }
}
