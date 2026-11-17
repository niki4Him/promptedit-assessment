export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    needs_submission_retry: boolean;
    estimated_user_charge_nanodollars: string;
    actual_user_charge_nanodollars: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string | null;
};

export type GenerationHistory = {
    data: Generation[];
    previous: string | null;
    next: string | null;
};
