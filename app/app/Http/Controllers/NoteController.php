<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        return NoteResource::collection(Note::latest('id')->paginate(15));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreNoteRequest $request): NoteResource
    {
        $note = Note::create($request->validated());
        return new NoteResource($note);
    }

    /**
     * Display the specified resource.
     */
    public function show(Note $note): NoteResource
    {
        return new NoteResource($note);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateNoteRequest $request, Note $note): NoteResource
    {
        $note->update($request->validated());
        return new NoteResource($note);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Note $note): Response
    {
        $note->delete();
        return response()->noContent();
    }
}
