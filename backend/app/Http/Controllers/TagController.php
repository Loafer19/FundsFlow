<?php

namespace App\Http\Controllers;

use App\Http\Requests\TagStoreRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tags = auth()
            ->user()
            ->tags()
            ->latest('title')
            ->get();

        return TagResource::collection($tags);
    }

    public function store(TagStoreRequest $request): TagResource
    {
        $tag = auth()
            ->user()
            ->tags()
            ->create($request->validated());

        return new TagResource($tag);
    }

    public function show(Tag $tag)
    {
        //
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
