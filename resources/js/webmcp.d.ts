/// <reference types="webmcp-types" />

export interface ConfirmRequest {
    tool: string;
    title: string;
    description: string;
    arguments: Record<string, unknown>;
    annotations?: Record<string, boolean>;
}

export interface WebMcpConfig {
    /** Asked before consequential tools run. Return false to decline. Default: window.confirm. */
    confirm: (request: ConfirmRequest) => boolean | Promise<boolean>;
    /** Polyfill hook: return the ModelContext to use instead of document.modelContext. */
    resolveModelContext: null | (() => WebMCP.ModelContext | null | undefined);
    /** Use navigator.modelContext when document.modelContext is missing (pre-spec, not part of the spec). Default true. */
    legacyNavigator: boolean;
    /** Refresh the manifest when the tab becomes visible again. Default true. */
    refreshOnFocus: boolean;
    /** Minimum milliseconds between focus refreshes. Default 30000. */
    refreshMinInterval: number;
    /** Bridge mode: bearer token provider (otherwise the same-origin session cookie / XSRF token is used). */
    bearer: null | (() => string | null | Promise<string | null>);
    /** Override the server's error convention: resolve with isError (default) or reject execute(). */
    errors: null | 'result' | 'reject';
    fetch: null | typeof fetch;
}

export interface ToolResult {
    content: Array<{ type: string; text?: string }>;
    isError?: boolean;
    [key: string]: unknown;
}

export type WebMcpEventName =
    | 'invoked'
    | 'succeeded'
    | 'failed'
    | 'registered'
    | 'unregistered'
    | 'refreshed'
    | 'error'
    | 'unsupported'
    | 'skipped'
    | 'auth-changed';

/** A tool definition for scopes (Livewire components, Alpine elements). */
export interface ScopeToolDefinition {
    name: string;
    description: string;
    title?: string;
    inputSchema?: object;
    annotations?: Record<string, boolean>;
    confirm?: boolean;
    exposedTo?: string[];
    [key: string]: unknown;
}

export type ScopeRunner = (
    def: ScopeToolDefinition,
    args: Record<string, unknown>,
    signal: AbortSignal | undefined,
) => unknown;

export interface Scope {
    readonly id: string;
    /** Reconcile registered tools with these definitions (abort removed/changed ones, register new ones). */
    sync(definitions: ScopeToolDefinition[]): { added: string[]; removed: string[] };
    /** Abort every tool of the scope. */
    dispose(): void;
    tools(): string[];
}

export interface RegisterResult {
    server: string;
    supported: boolean;
    tools: string[];
}

export interface WebMcpApi {
    readonly version: number;
    /** Register a server by slug (reads the embedded manifest) or by manifest payload. */
    register(server: string | object): RegisterResult | null;
    /** Register tools that run in the browser or through a framework adapter (used by Livewire and Alpine). */
    registerScope(id: string, definitions: ScopeToolDefinition[], options: { runner: ScopeRunner; errors?: 'result' | 'reject' }): Scope;
    /** Turn a return value into the result shape agents get (string -> text, object -> JSON text, nothing -> "Done."). */
    toResult(value: unknown): ToolResult;
    /** Run the confirm hook (false when declined or when the hook throws). */
    confirm(request: ConfirmRequest): Promise<boolean>;
    /** POST a tool call to a Session route (used by declarative forms): same headers and error mapping as server tools. */
    postTool(url: string, args: Record<string, unknown>, options?: { csrf?: string | null; header?: string; signal?: AbortSignal; confirmation?: string | null }): Promise<ToolResult>;
    /** Abort (unregister) every tool of a server. */
    unregister(slug: string): void;
    /** Re-fetch the manifest for the current user and reconcile registered tools. */
    refresh(slug?: string): Promise<unknown>;
    configure(options: Partial<WebMcpConfig>): void;
    /** Register every known server and scope against the ModelContext available now (late polyfill). */
    resync(): void;
    on(name: WebMcpEventName, listener: (event: CustomEvent) => void): void;
    off(name: WebMcpEventName, listener: (event: CustomEvent) => void): void;
    createDialogConfirm(labels?: {
        title?: (request: ConfirmRequest) => string;
        confirmLabel?: string;
        cancelLabel?: string;
    }): (request: ConfirmRequest) => Promise<boolean>;
    isSupported(): boolean;
    servers(): string[];
    tools(slug: string): string[];
}

export const WebMcp: WebMcpApi;
export function register(server: string | object): RegisterResult | null;
export function registerScope(id: string, definitions: ScopeToolDefinition[], options: { runner: ScopeRunner; errors?: 'result' | 'reject' }): Scope;
export function toResult(value: unknown): ToolResult;
export function confirm(request: ConfirmRequest): Promise<boolean>;
export function postTool(url: string, args: Record<string, unknown>, options?: { csrf?: string | null; header?: string; signal?: AbortSignal; confirmation?: string | null }): Promise<ToolResult>;
export function unregister(slug: string): void;
export function refresh(slug?: string): Promise<unknown>;
export function configure(options: Partial<WebMcpConfig>): void;
export function on(name: WebMcpEventName, listener: (event: CustomEvent) => void): void;
export function off(name: WebMcpEventName, listener: (event: CustomEvent) => void): void;
export function createDialogConfirm(labels?: Parameters<WebMcpApi['createDialogConfirm']>[0]): ReturnType<WebMcpApi['createDialogConfirm']>;
export function autoRegister(): void;
export function resetForTests(): void;
export default WebMcp;

declare global {
    interface Window {
        WebMcp?: WebMcpApi;
    }
}
