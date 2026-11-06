<?php

it('serves the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('does not expose a web UI route', function () {
    $this->get('/')->assertNotFound();
});
