export const API_URL = import.meta.env.VITE_API_URL as string | undefined;
export const DEMO = import.meta.env.VITE_DEMO === '1' || !API_URL;
