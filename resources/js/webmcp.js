/**
 * Public entry point of the sytxlabs/laravel-webmcp browser runtime.
 *
 * The code lives in webmcp-core.js. This file only re-exports it, so loading it twice (the Blade tag adds a cache-busting `?v=...`, the Livewire/Alpine/forms adapters import it plain)
 * still gives ONE runtime: both copies of this file import the same, single webmcp-core.js module.
 */
export {WebMcp, register, registerScope, toResult, postTool, confirm, unregister, refresh, configure, on, off, createDialogConfirm, autoRegister, resetForTests} from './webmcp-core.js';
export { default } from './webmcp-core.js';
