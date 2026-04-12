export interface Pharmacist {
  id: number
  name: string
  license_no: string | null
  specialties: string[]
  image_url: string | null
  is_online: boolean
  rating: number | null
}
