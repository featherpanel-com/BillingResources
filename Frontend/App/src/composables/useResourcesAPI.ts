import { ref } from "vue";
import axios from "axios";
import type { AxiosError } from "axios";

export interface ApiResponse<T> {
  success: boolean;
  message?: string;
  data?: T;
  error?: boolean;
  error_message?: string;
  error_code?: string;
}

export interface UserResources {
  id: number | null;
  user_id: number | null;
  memory_limit: number;
  cpu_limit: number;
  disk_limit: number;
  server_limit: number;
  database_limit: number;
  backup_limit: number;
  allocation_limit: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export type ResourceLimits = {
  memory_limit: number;
  cpu_limit: number;
  disk_limit: number;
  server_limit: number;
  database_limit: number;
  backup_limit: number;
  allocation_limit: number;
};

export interface UserResourcesResponse {
  limits: ResourceLimits;
  used: ResourceLimits;
  max_limits: ResourceLimits;
}

export function useResourcesAPI() {
  const loading = ref(false);
  const error = ref<string | null>(null);

  const handleError = (err: unknown): string => {
    if (axios.isAxiosError(err)) {
      const axiosError = err as AxiosError<{ error_message?: string }>;
      return (
        axiosError.response?.data?.error_message ||
        axiosError.message ||
        "An error occurred"
      );
    }
    return err instanceof Error ? err.message : "An unknown error occurred";
  };

  const getResources = async (): Promise<UserResourcesResponse> => {
    loading.value = true;
    error.value = null;
    try {
      const response = await axios.get<ApiResponse<UserResourcesResponse>>(
        `/api/user/billingresources/resources`
      );

      if (response.data && response.data.success && response.data.data) {
        return response.data.data;
      }

      throw new Error(
        response.data?.error_message || "Invalid response format"
      );
    } catch (err) {
      const errorMsg = handleError(err);
      error.value = errorMsg;
      throw new Error(errorMsg);
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    error,
    getResources,
  };
}
