<?php

it('reports a healthy application', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
        ]);
});
