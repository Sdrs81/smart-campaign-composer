import { graphemeLength } from './graphemeLength'

// Same pattern as the backend's PersonalizationRenderer: spaces inside the braces are tolerated.
const PLACEHOLDER = /\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g

// Stands in for one visible character; it never joins with its neighbours into a larger grapheme.
const WIDTH_FILLER = 'x'

export interface TemplateVariable {
  name: string
  fallback: string
}

/**
 * The longest the rendered text can get, in visible characters (K13). A variable counts as the longer of the
 * audience's longest value and its fallback; an unknown placeholder stays as the literal text it will remain.
 * Mirrors `PersonalizationRenderer::worstCaseLength`, so the counter and the server agree.
 */
export function worstCaseLength(
  template: string,
  longestValues: Record<string, number>,
  variables: TemplateVariable[],
): number {
  const fallbacks = new Map(variables.map((variable) => [variable.name, variable.fallback]))

  const worstCase = template.replace(PLACEHOLDER, (placeholder, name: string) => {
    const fallback = fallbacks.get(name)
    if (fallback === undefined) return placeholder
    return WIDTH_FILLER.repeat(Math.max(longestValues[name] ?? 0, graphemeLength(fallback)))
  })

  return graphemeLength(worstCase)
}
