/**
 * Product copy for three separate channels (do not conflate labels).
 * Thai is primary UI; English for subtitles / bilingual clarity.
 */
export const miniappChannelCopy = {
  ai: {
    titleTh: 'ผู้ช่วย AI',
    titleEn: 'AI assistant',
    subtitleTh: 'สอบถามสินค้าและสุขภาพ',
    subtitleEn: 'Ask about products and health',
    /** Home hero CTA — pharmacy-flavored */
    homeCtaTh: 'แชท AI เภสัชกร',
    homeCtaEn: 'Chat with AI pharmacist',
  },
  liveChat: {
    titleTh: 'Live Chat',
    titleEn: 'Live chat',
    subtitleTh: 'คุยกับเจ้าหน้าที่ในแอปนี้',
    subtitleEn: 'Chat with staff inside this app',
    unavailableTh: 'ยังไม่เปิดบริการ Live Chat',
    unavailableEn: 'Live chat is not available yet',
  },
  lineOa: {
    titleTh: 'แชทผ่านไลน์',
    titleEn: 'Chat on LINE',
    subtitleTh: 'คุยกับแบรนด์ผ่าน LINE Official Account',
    subtitleEn: 'Message the brand via LINE OA',
  },
} as const
