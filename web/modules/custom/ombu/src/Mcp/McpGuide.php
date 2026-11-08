<?php

declare(strict_types=1);

namespace Drupal\ombu\Mcp;

/**
 * What a plain GET of /mcp answers.
 *
 * What the server is, how to connect and the same market over plain HTTP, for
 * a client that cannot speak MCP (a fetch tool, a browser, curl). A real MCP
 * client POSTs; one that asks for the event stream still gets the protocol's
 * own 405.
 */
final class McpGuide {

  /**
   * The guide as a structure; $base is the site's address without a slash.
   */
  public static function data(string $base): array {
    $mcp = "$base/mcp";
    return [
      'name' => 'Ombú',
      'what' => 'A market of pay-per-call services for AI agents, paid in USDC over x402. No sign-in.',
      'mcp' => [
        'url' => $mcp,
        'transport' => 'streamable-http',
        'auth' => 'none',
        'tools' => [
          [
            'name' => 'ombu_search_services',
            'cost' => 'free',
            'arguments' => [
              'search' => 'words',
              'category' => 'optional',
              'network' => 'optional, e.g. eip155:8453',
              'limit' => 'optional',
              'offset' => 'optional',
            ],
          ],
          [
            'name' => 'ombu_get_service',
            'cost' => 'free',
            'arguments' => ['id' => 'metadata.market.id of a search result (required)'],
          ],
          [
            'name' => 'ombu_call_service',
            'cost' => 'paid in USDC, in the call itself (x402)',
            'arguments' => ['id' => 'metadata.market.id of a search result (required)', 'input' => "the service's input"],
          ],
        ],
      ],
      'connect' => [
        'claude_code' => "claude mcp add --transport http ombu $mcp",
        'cursor' => '.cursor/mcp.json: {"mcpServers": {"ombu": {"url": "' . $mcp . '"}}}',
        'vscode' => '.vscode/mcp.json: {"servers": {"ombu": {"type": "http", "url": "' . $mcp . '"}}}',
        'any_other_client' => "Add $mcp as a remote (Streamable HTTP) MCP server, no authentication.",
      ],
      'without_mcp' => [
        'search_json' => "$base/market.json?search={words}",
        'search_markdown' => "$base/market.md?search={words}",
        'one_service' => "$base/product/{id}.md",
        'guide_for_agents' => "$base/llms.txt",
        'openapi' => "$base/openapi.json",
      ],
    ];
  }

  /**
   * The guide as Markdown.
   */
  public static function markdown(string $base): string {
    $d = self::data($base);
    $md = "# Ombú MCP server\n\n{$d['what']}\n\n";
    $md .= "- **URL:** `{$d['mcp']['url']}`\n- **Transport:** Streamable HTTP (MCP clients POST; this GET is only a guide)\n- **Sign-in:** none\n\n";
    $md .= "## Tools\n\n";
    foreach ($d['mcp']['tools'] as $tool) {
      $args = [];
      foreach ($tool['arguments'] as $name => $what) {
        $args[] = "`$name` ($what)";
      }
      $md .= "- `{$tool['name']}`, {$tool['cost']}. Arguments: " . implode(', ', $args) . ".\n";
    }
    $md .= "\n## Connect\n\n";
    foreach ($d['connect'] as $client => $how) {
      $md .= '- **' . str_replace('_', ' ', $client) . ":** `$how`\n";
    }
    $md .= "\n## Without MCP, with a plain GET\n\n";
    foreach ($d['without_mcp'] as $what => $url) {
      $md .= '- ' . str_replace('_', ' ', $what) . ": $url\n";
    }
    return $md;
  }

}
