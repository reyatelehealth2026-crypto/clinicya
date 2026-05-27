import { appConfig } from '@/lib/config'
import { phpGet, phpPost } from '@/lib/php-bridge'

export type AddressLabel = 'primary' | 'secondary_1' | 'secondary_2' | 'secondary_3'

export type UserAddress = {
  label: AddressLabel
  name: string | null
  phone: string | null
  address: string | null
  subdistrict: string | null
  district: string | null
  province: string | null
  postcode: string | null
  updated_at?: string | null
}

export type ListAddressesResponse = {
  success: boolean
  addresses: UserAddress[]
  error?: string
}

export type UpsertAddressResponse = {
  success: boolean
  message?: string
  address?: UserAddress
  error?: string
}

export const ADDRESS_LABELS: AddressLabel[] = ['primary', 'secondary_1', 'secondary_2', 'secondary_3']

export const ADDRESS_LABEL_TH: Record<AddressLabel, string> = {
  primary: 'ที่อยู่หลัก',
  secondary_1: 'ที่อยู่สำรอง 1',
  secondary_2: 'ที่อยู่สำรอง 2',
  secondary_3: 'ที่อยู่สำรอง 3'
}

export function listAddresses(lineUserId: string) {
  return phpGet<ListAddressesResponse>('/api/user-addresses.php', {
    action: 'list',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId
  })
}

export function upsertAddress(lineUserId: string, label: AddressLabel, data: Omit<UserAddress, 'label' | 'updated_at'>) {
  return phpPost<UpsertAddressResponse>('/api/user-addresses.php', {
    action: 'upsert',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    label,
    ...data
  })
}

export function deleteAddress(lineUserId: string, label: AddressLabel) {
  return phpPost<{ success: boolean; message?: string; error?: string }>('/api/user-addresses.php', {
    action: 'delete',
    line_user_id: lineUserId,
    line_account_id: appConfig.lineAccountId,
    label
  })
}

/** True when the address has at least one meaningful field filled. */
export function addressHasData(a: Pick<UserAddress, 'name' | 'phone' | 'address'>): boolean {
  return Boolean((a.name && a.name.trim()) || (a.phone && a.phone.trim()) || (a.address && a.address.trim()))
}

/** Pretty single-line address for summary display. */
export function formatAddressLine(a: UserAddress): string {
  return [a.address, a.subdistrict, a.district, a.province, a.postcode]
    .filter(Boolean)
    .join(' ')
}
