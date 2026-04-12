import { appConfig } from '@/lib/config'
import type { RewardItem } from '@/types/rewards'

export function buildRewardShareText(reward: RewardItem) {
  const lines = [
    `🎁 ของรางวัลน่าแลกจาก ${appConfig.miniAppName}`,
    reward.name,
    `ใช้เพียง ${reward.points_required.toLocaleString()} คะแนน`
  ]

  if (reward.description) {
    lines.push(reward.description)
  }

  if (typeof reward.stock === 'number' && reward.stock >= 0) {
    lines.push(reward.stock === 0 ? 'สถานะ: ของรางวัลหมดแล้ว' : `เหลือสิทธิ์ ${reward.stock} รายการ`)
  }

  lines.push('เข้ามาดูของรางวัลและแลกแต้มได้ใน LINE Mini App')

  return lines.join('\n')
}

export function buildRewardShareMessage(reward: RewardItem) {
  const stockText = typeof reward.stock === 'number' && reward.stock >= 0
    ? reward.stock === 0
      ? 'ของรางวัลหมดแล้ว'
      : `เหลือ ${reward.stock} รายการ`
    : 'แลกได้ผ่าน LINE Mini App'

  const description = reward.description?.trim() || 'ของรางวัลพิเศษสำหรับสมาชิก ใช้แต้มสะสมแลกได้ทันทีในมินิแอป'

  const bodyContents: Array<Record<string, unknown>> = []

  if (reward.image_url) {
    bodyContents.push({
      type: 'image',
      url: reward.image_url,
      size: 'full',
      aspectMode: 'cover',
      aspectRatio: '20:13',
      gravity: 'center'
    })
  }

  bodyContents.push(
    {
      type: 'box',
      layout: 'vertical',
      paddingAll: '16px',
      spacing: '10px',
      contents: [
        {
          type: 'box',
          layout: 'baseline',
          contents: [
            {
              type: 'text',
              text: 'REWARD PICK',
              size: 'xs',
              weight: 'bold',
              color: '#06C755'
            }
          ]
        },
        {
          type: 'text',
          text: reward.name,
          wrap: true,
          weight: 'bold',
          size: 'lg',
          color: '#111827'
        },
        {
          type: 'box',
          layout: 'baseline',
          spacing: '6px',
          contents: [
            {
              type: 'text',
              text: `${reward.points_required.toLocaleString()} คะแนน`,
              weight: 'bold',
              size: 'md',
              color: '#06C755',
              flex: 0
            },
            {
              type: 'text',
              text: stockText,
              size: 'xs',
              color: '#6B7280',
              wrap: true
            }
          ]
        },
        {
          type: 'text',
          text: description,
          wrap: true,
          size: 'sm',
          color: '#4B5563'
        },
        {
          type: 'separator',
          margin: 'md'
        },
        {
          type: 'box',
          layout: 'vertical',
          margin: 'md',
          backgroundColor: '#F0FDF4',
          cornerRadius: '12px',
          paddingAll: '12px',
          contents: [
            {
              type: 'text',
              text: 'ใช้แต้มสะสมแลกของรางวัลได้ใน LINE Mini App',
              wrap: true,
              size: 'sm',
              weight: 'bold',
              color: '#166534'
            },
            {
              type: 'text',
              text: appConfig.miniAppName,
              margin: 'sm',
              size: 'xs',
              color: '#15803D'
            }
          ]
        }
      ]
    }
  )

  return {
    type: 'flex',
    altText: `ของรางวัลน่าแลก: ${reward.name}`,
    contents: {
      type: 'bubble',
      size: 'mega',
      body: {
        type: 'box',
        layout: 'vertical',
        paddingAll: '0px',
        contents: bodyContents
      }
    }
  }
}
