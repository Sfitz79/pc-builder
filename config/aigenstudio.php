<?php

/**
 * Integration endpoints for the aigen Studio media stack (local ComfyUI +
 * generate-media gateway). Used by the 3D build viewport's "render to
 * storefront" action.
 */

return [
    'aigenUrl' => env('AIGEN_URL', 'http://127.0.0.1:3000'),
    'comfyUrl' => env('COMFYUI_URL', 'http://127.0.0.1:8188'),
];