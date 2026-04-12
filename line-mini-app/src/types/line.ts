export interface LineProfile {
  userId: string
  displayName: string
  pictureUrl?: string
  statusMessage?: string
}

export interface LineBootstrapState {
  isReady: boolean
  isLoggedIn: boolean
  isInClient: boolean
  profile: LineProfile | null
  accessToken: string | null
  error: string | null
  needsLogin?: boolean
}

export interface ServiceNotificationToken {
  notificationToken: string
  expiresIn: number
  remainingCount: number
  sessionId?: string
}

export interface MiniAppCapabilities {
  canUseQuickFill: boolean
  canUseServiceMessages: boolean
  canUseIap: boolean
}
