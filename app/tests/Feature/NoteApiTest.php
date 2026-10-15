<?php

use App\Models\Note;

it('lists notes with pagination', function () {
    Note::factory()->count(20)->create();

    $this->getJson('/api/notes')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20);
});

it('creates a note', function () {
    $this->postJson('/api/notes', ['title' => 'First', 'body' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'First')
        ->assertJsonPath('data.body', 'Hello');

    $this->assertDatabaseHas('notes', ['title' => 'First', 'body' => 'Hello']);
});

it('creates a note without body', function () {
    $this->postJson('/api/notes', ['title' => 'Only title'])
        ->assertCreated()
        ->assertJsonPath('data.body', null);
});

it('rejects a note without title', function () {
    $this->postJson('/api/notes', ['body' => 'No title'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('rejects a title longer than 255 characters', function () {
    $this->postJson('/api/notes', ['title' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('returns json errors without an Accept header', function () {
    $this->post('/api/notes', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json');
});

it('shows a note', function () {
    $note = Note::factory()->create(['title' => 'Shown']);

    $this->getJson("/api/notes/{$note->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $note->id)
        ->assertJsonPath('data.title', 'Shown');
});

it('returns 404 for a missing note', function () {
    $this->get('/api/notes/999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('returns 404 for a non-numeric id', function () {
    $this->get('/api/notes/abc')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('updates only the given fields', function () {
    $note = Note::factory()->create(['title' => 'Old', 'body' => 'Old body']);

    $this->patchJson("/api/notes/{$note->id}", ['body' => 'New body'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Old')
        ->assertJsonPath('data.body', 'New body');
});

it('rejects an empty title on update', function () {
    $note = Note::factory()->create();

    $this->patchJson("/api/notes/{$note->id}", ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('deletes a note', function () {
    $note = Note::factory()->create();

    $this->deleteJson("/api/notes/{$note->id}")->assertNoContent();

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});
