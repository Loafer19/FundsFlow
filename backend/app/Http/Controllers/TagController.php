<?php

namespace App\Http\Controllers;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\DeleteTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Tags\RestoreTagAction;
use App\Actions\Tags\UpdateTagAction;
use App\Http\Requests\TagStoreRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function __construct(
        private readonly ListTagsAction $listTags,
        private readonly CreateTagAction $createTag,
        private readonly UpdateTagAction $updateTag,
        private readonly DeleteTagAction $deleteTag,
        private readonly RestoreTagAction $restoreTag,
    ) {}

    /** Active and archived tags (archived ones still label their old transactions). */
    public function index(): AnonymousResourceCollection
    {
        $tags = $this->listTags->execute(auth()->user(), withArchived: true);

        return TagResource::collection($tags);
    }

    public function store(TagStoreRequest $request): TagResource
    {
        $tag = $this->createTag->execute(auth()->user(), $request->validated());

        return new TagResource($tag);
    }

    public function update(Tag $tag, TagStoreRequest $request): TagResource
    {
        $tag = $this->updateTag->execute(auth()->user(), $tag, $request->validated());

        return new TagResource($tag);
    }

    /** Archive: hidden from pickers and new transactions; existing records keep the tag. */
    public function destroy(Tag $tag): JsonResponse
    {
        $this->deleteTag->execute(auth()->user(), $tag);

        return response()->json([
            'message' => 'Tag archived!',
        ]);
    }

    /** Back from the archive. A restored parent does not restore its children. */
    public function restore(int $tag): TagResource
    {
        $model = auth()->user()->tags()->onlyTrashed()->findOrFail($tag);
        $restored = $this->restoreTag->execute(auth()->user(), $model);

        return new TagResource($restored);
    }
}
