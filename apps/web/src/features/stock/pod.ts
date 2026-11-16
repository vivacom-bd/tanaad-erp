/** The roles the server lets confirm a delivery (apps/api Dispatch authoriser: ConfirmDelivery). Everyone else sees the shipment read-only. */
export const POD_ROLES = ['storekeeper', 'gate', 'site_manager', 'driver'] as const;
export const canConfirmDelivery = (role: string | undefined): boolean => !!role && (POD_ROLES as readonly string[]).includes(role);
