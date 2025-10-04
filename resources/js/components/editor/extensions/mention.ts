import Mention from '@tiptap/extension-mention'
import { mergeAttributes } from '@tiptap/core'

export interface MentionAttributes {
  id: number | string
  nickname: string
  label?: string
  profileUrl?: string | null
}

export const MentionExtension = Mention.extend({
  addAttributes() {
    return {
      id: {
        default: null,
      },
      nickname: {
        default: null,
      },
      label: {
        default: null,
      },
      profileUrl: {
        default: null,
      },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-type="mention"]',
      },
      {
        tag: 'a[data-type="mention"]',
      },
    ]
  },

  renderHTML({ node, HTMLAttributes }) {
    const attrs = node.attrs as MentionAttributes
    const label = attrs.label ?? attrs.nickname ?? ''
    const nickname = attrs.nickname ?? label
    const tagName = attrs.profileUrl ? 'a' : 'span'

    const merged = mergeAttributes(HTMLAttributes, {
      'data-type': 'mention',
      'data-id': attrs.id ?? '',
      'data-nickname': nickname,
      'data-profile-url': attrs.profileUrl ?? '',
      class: 'mention text-primary font-medium hover:underline',
    })

    if (attrs.profileUrl) {
      merged.href = attrs.profileUrl
    }

    return [tagName, merged, `@${label}`]
  },

  renderText({ node }) {
    const attrs = node.attrs as MentionAttributes

    return `@${attrs.label ?? attrs.nickname ?? ''}`
  },
})

export default MentionExtension
