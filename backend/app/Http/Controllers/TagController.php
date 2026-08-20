<?php

namespace App\Http\Controllers;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\DeleteTagAction;
use App\Actions\Tags\ListTagsAction;
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
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $tags = $this->listTags->execute(auth()->user());

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

    public function destroy(Tag $tag): JsonResponse
    {
        $this->deleteTag->execute(auth()->user(), $tag);

        return response()->json([
            'message' => 'Tag deleted successfully!',
        ]);
    }
}
