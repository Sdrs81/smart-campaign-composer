const segmenter = new Intl.Segmenter('tr', { granularity: 'grapheme' })

/** Characters as the reader sees them (emoji and combined letters count once), like the backend's grapheme count. */
export function graphemeLength(text: string): number {
  let count = 0
  for (const _ of segmenter.segment(text)) count++
  return count
}
