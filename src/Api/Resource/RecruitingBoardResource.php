<?php

namespace Ernestdefoe\Recruiting\Api\Resource;

use Ernestdefoe\Recruiting\Service\RecruitingBoards;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractResource;
use Flarum\Api\Resource\Contracts\Findable;
use Flarum\Api\Schema;
use Tobyz\JsonApiServer\Context;

/**
 * GET /api/recruiting-boards/1: the configured recruiting class.
 *
 * A singleton resource (like core's mail-settings): there is one board per
 * forum. The board is public data, cached once for everyone, and the page
 * and its nav link are shown to guests, so it is gated on the forum's own
 * view permission: a forum closed to guests stays closed.
 *
 * @extends AbstractResource<RecruitingBoard>
 */
class RecruitingBoardResource extends AbstractResource implements Findable
{
    public function __construct(private RecruitingBoards $boards)
    {
    }

    public function type(): string
    {
        return 'recruiting-boards';
    }

    public function getId(object $model, Context $context): string
    {
        return '1';
    }

    /**
     * Runs before the permission check, so it does no work: the board is
     * loaded when its first field is serialized.
     */
    public function find(string $id, Context $context): ?object
    {
        return $id === '1' ? new RecruitingBoard(fn () => $this->boards->load()) : null;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                // viewForum is a global ability: checked without the board,
                // which ->can() would pass to the gate as a model.
                ->visible(fn (RecruitingBoard $board, FlarumContext $context) => $context->getActor()->can('viewForum')),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('year')
                ->get(fn (RecruitingBoard $board) => $board->get('year')),
            Schema\Arr::make('recruits')
                ->get(fn (RecruitingBoard $board) => $board->get('recruits')),
            Schema\Str::make('error')
                ->nullable()
                ->get(fn (RecruitingBoard $board) => $board->get('error')),
        ];
    }
}
