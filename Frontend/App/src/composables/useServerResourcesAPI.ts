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
  errors?: Array<{ code?: string; detail?: string; status?: number }>;
}

export interface ServerResource {
  id: number;
  name: string;
  uuid: string;
  memory: number;
  cpu: number;
  disk: number;
  database_limit: number;
  backup_limit: number;
  allocation_limit: number;
}

export interface ResourceLimits {
  memory_limit: number;
  cpu_limit: number;
  disk_limit: number;
  database_limit: number;
  backup_limit: number;
  allocation_limit: number;
}

export interface OverflowDetails {
  [key: string]: {
    used?: number;
    server_value?: number;
    limit: number;
  };
}

export interface OverflowCheck {
  has_overflow: boolean;
  overflow_details: OverflowDetails;
}

export interface ServerResourcesResponse {
  server: {
    id: number;
    name: string;
    uuid: string;
    resources: {
      memory: number;
      cpu: number;
      disk: number;
      database_limit: number;
      backup_limit: number;
      allocation_limit: number;
    };
  };
  available: ResourceLimits; // For display: what's actually left (limit - total_used)
  available_for_edit: ResourceLimits; // For editing: what can be allocated (limit - used_by_others)
  limits: ResourceLimits;
  used: ResourceLimits;
  total_used: ResourceLimits;
  server_overflow: OverflowCheck;
  total_overflow: OverflowCheck;
}

export interface UpdateServerResourcesRequest {
  memory?: number;
  cpu?: number;
  disk?: number;
  database_limit?: number;
  backup_limit?: number;
  allocation_limit?: number;
}

export function useServerResourcesAPI() {
  const loading = ref(false);
  const error = ref<string | null>(null);

  const handleError = (err: unknown): string => {
    if (axios.isAxiosError(err)) {
      const axiosError = err as AxiosError<ApiResponse<{ errors?: string[] }>>;
      // Check for errors in data.data.errors (nested structure from validation)
      if (
        axiosError.response?.data?.data &&
        typeof axiosError.response.data.data === "object" &&
        "errors" in axiosError.response.data.data
      ) {
        const dataErrors = (
          axiosError.response.data.data as { errors?: string[] }
        ).errors;
        if (Array.isArray(dataErrors) && dataErrors.length > 0) {
          return dataErrors.join(", ");
        }
      }
      return (
        axiosError.response?.data?.error_message ||
        axiosError.response?.data?.message ||
        axiosError.message ||
        "An error occurred"
      );
    }
    return err instanceof Error ? err.message : "An unknown error occurred";
  };

  const getServerResources = async (
    uuidShort: string
  ): Promise<ServerResourcesResponse> => {
    loading.value = true;
    error.value = null;
    try {
      const response = await axios.get<ApiResponse<ServerResourcesResponse>>(
        `/api/user/servers/${uuidShort}/billingresources`
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

  const updateServerResources = async (
    uuidShort: string,
    resources: UpdateServerResourcesRequest
  ): Promise<void> => {
    loading.value = true;
    error.value = null;
    try {
      const response = await axios.patch<ApiResponse<void>>(
        `/api/user/servers/${uuidShort}/billingresources`,
        resources
      );

      if (response.data && response.data.success) {
        return;
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
    getServerResources,
    updateServerResources,
  };
}
