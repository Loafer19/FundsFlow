<?php

namespace App\Http\Controllers;

use App\Http\Requests\TagStoreRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tag::class);

        $tags = auth()
            ->user()
            ->tags()
            ->get();

        return TagResource::collection($tags);
    }

    public function store(TagStoreRequest $request): TagResource
    {
        Gate::authorize('create', Tag::class);

        $tag = auth()
            ->user()
            ->tags()
            ->create($request->validated());

        return new TagResource($tag);
    }

    public function update(UpdateTagRequest $request, Tag $tag)
    {
        //
    }

    public function destroy(Tag $tag)
    {
        //
    }
}
