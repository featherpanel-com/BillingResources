<script setup lang="ts">
import { ref, onMounted } from "vue";
import { Card } from "@/components/ui/card";
import { useResourcesAPI } from "@/composables/useResourcesAPI";
import type { UserResourcesResponse } from "@/composables/useResourcesAPI";

const { loading, getResources } = useResourcesAPI();
const resourcesData = ref<UserResourcesResponse | null>(null);

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return "0 MB";
  const mb = bytes;
  if (mb >= 1024) {
    return `${(mb / 1024).toFixed(1)} GB`;
  }
  return `${Math.round(mb)} MB`;
};

const formatPercentage = (value: number): string => {
  return `${value}%`;
};

const getUsagePercentage = (used: number, limit: number): number => {
  if (limit === 0) return 0;
  return Math.min(Math.round((used / limit) * 100), 100);
};

const getProgressClass = (percentage: number): string => {
  if (percentage >= 90) return "danger";
  if (percentage >= 70) return "warning";
  if (percentage >= 50) return "";
  return "success";
};

const getBadgeClass = (percentage: number): string => {
  if (percentage >= 100) return "overflow";
  if (percentage >= 90) return "high";
  if (percentage >= 70) return "medium";
  return "low";
};

const isOverflow = (used: number, limit: number): boolean => {
  if (limit === 0) return false; // Unlimited
  return used > limit;
};

const resources = [
  { key: "memory_limit", label: "Memory", icon: "💾", format: formatBytes },
  { key: "cpu_limit", label: "CPU", icon: "⚡", format: formatPercentage },
  { key: "disk_limit", label: "Storage", icon: "💿", format: formatBytes },
  {
    key: "server_limit",
    label: "Servers",
    icon: "🖥️",
    format: (v: number) => v.toString(),
  },
  {
    key: "database_limit",
    label: "Databases",
    icon: "🗄️",
    format: (v: number) => v.toString(),
  },
  {
    key: "backup_limit",
    label: "Backups",
    icon: "📦",
    format: (v: number) => v.toString(),
  },
  {
    key: "allocation_limit",
    label: "Ports",
    icon: "🌐",
    format: (v: number) => v.toString(),
  },
];

const loadResources = async () => {
  try {
    resourcesData.value = await getResources();
  } catch (err) {
    console.error("Failed to load resources:", err);
  }
};

onMounted(() => {
  loadResources();
});
</script>

<template>
  <div class="w-full overflow-hidden rounded-2xl border-0 bg-transparent shadow-none ring-0">
    <div class="border-b border-border/25 bg-transparent px-4 py-3.5 sm:px-5">
      <h3 class="text-foreground text-base font-bold tracking-tight sm:text-lg">
        Resource Usage
      </h3>
      <p class="text-muted-foreground mt-0.5 max-w-xl text-[11px] leading-snug sm:text-xs">
        Monitor your resource consumption across all servers
      </p>
    </div>

    <div class="p-3 sm:p-4">
      <div
        v-if="loading && !resourcesData"
        class="flex min-h-[120px] items-center justify-center py-6"
      >
        <div
          class="border-primary h-7 w-7 animate-spin rounded-full border-2 border-t-transparent"
        ></div>
      </div>

      <div
        v-else-if="resourcesData"
        class="resource-grid grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 md:grid-cols-4 lg:grid-cols-4 lg:gap-3"
      >
        <Card
          v-for="resource in resources"
          :key="resource.key"
          class="w-full gap-0! rounded-xl border border-border/40 bg-transparent px-2.5 py-3 shadow-none transition-all duration-200 hover:border-primary/30 sm:px-3 dark:bg-transparent dark:hover:bg-muted/15"
        >
          <div class="flex flex-col items-center text-center">
            <div
              class="mb-2 flex h-10 w-10 items-center justify-center rounded-xl bg-linear-to-br from-primary/25 to-primary/5 text-xl shadow-inner ring-1 ring-primary/10 sm:h-11 sm:w-11"
            >
              {{ resource.icon }}
            </div>
          <div class="w-full">
            <div
              class="text-muted-foreground mb-1.5 text-[9px] font-semibold uppercase tracking-wider"
            >
              {{ resource.label }}
            </div>
            <div class="mb-1.5 flex items-baseline justify-center gap-1">
              <span class="text-foreground text-sm font-bold tabular-nums sm:text-[15px]">
                {{
                  resource.format(
                    (resourcesData.used[
                      resource.key as keyof typeof resourcesData.used
                    ] as number) || 0,
                  )
                }}
              </span>
              <span class="text-muted-foreground text-xs">/</span>
              <span
                class="text-xs font-medium"
                :class="
                  (resourcesData.limits[
                    resource.key as keyof typeof resourcesData.limits
                  ] as number) === 0 ||
                  (resourcesData.max_limits[
                    resource.key as keyof typeof resourcesData.max_limits
                  ] as number) === 0
                    ? 'text-green-500'
                    : 'text-muted-foreground'
                "
              >
                {{
                  (resourcesData.limits[
                    resource.key as keyof typeof resourcesData.limits
                  ] as number) === 0 ||
                  (resourcesData.max_limits[
                    resource.key as keyof typeof resourcesData.max_limits
                  ] as number) === 0
                    ? "∞"
                    : resource.format(
                        (resourcesData.limits[
                          resource.key as keyof typeof resourcesData.limits
                        ] as number) || 0,
                      )
                }}
              </span>
            </div>

            <div
              v-if="
                (resourcesData.limits[
                  resource.key as keyof typeof resourcesData.limits
                ] as number) !== 0 &&
                (resourcesData.max_limits[
                  resource.key as keyof typeof resourcesData.max_limits
                ] as number) !== 0
              "
              class="space-y-1"
            >
              <div class="flex items-center justify-between text-[9px]">
                <span class="text-muted-foreground font-medium">Usage</span>
                <span
                  class="px-1 py-0.5 rounded text-[9px] font-semibold"
                  :class="{
                    'bg-red-600/20 text-red-400 border border-red-500/30':
                      getBadgeClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'overflow',
                    'bg-red-500/15 text-red-400':
                      getBadgeClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'high',
                    'bg-yellow-500/15 text-yellow-400':
                      getBadgeClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'medium',
                    'bg-green-500/15 text-green-400':
                      getBadgeClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'low',
                  }"
                >
                  {{
                    getUsagePercentage(
                      (resourcesData.used[
                        resource.key as keyof typeof resourcesData.used
                      ] as number) || 0,
                      (resourcesData.limits[
                        resource.key as keyof typeof resourcesData.limits
                      ] as number) || 0,
                    )
                  }}%
                </span>
              </div>
              <div
                class="bg-muted/60 h-1.5 w-full overflow-hidden rounded-full ring-1 ring-inset ring-border/30 dark:bg-muted/40"
              >
                <div
                  class="h-full rounded-full transition-all duration-500"
                  :class="{
                    'bg-linear-to-r from-red-600 to-red-700 animate-pulse':
                      isOverflow(
                        (resourcesData.used[
                          resource.key as keyof typeof resourcesData.used
                        ] as number) || 0,
                        (resourcesData.limits[
                          resource.key as keyof typeof resourcesData.limits
                        ] as number) || 0,
                      ),
                    'bg-linear-to-r from-red-500 to-red-600':
                      !isOverflow(
                        (resourcesData.used[
                          resource.key as keyof typeof resourcesData.used
                        ] as number) || 0,
                        (resourcesData.limits[
                          resource.key as keyof typeof resourcesData.limits
                        ] as number) || 0,
                      ) &&
                      getProgressClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'danger',
                    'bg-linear-to-r from-yellow-500 to-yellow-600':
                      getProgressClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'warning',
                    'bg-linear-to-r from-blue-500 to-indigo-500':
                      getProgressClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === '',
                    'bg-linear-to-r from-green-500 to-green-600':
                      getProgressClass(
                        getUsagePercentage(
                          (resourcesData.used[
                            resource.key as keyof typeof resourcesData.used
                          ] as number) || 0,
                          (resourcesData.limits[
                            resource.key as keyof typeof resourcesData.limits
                          ] as number) || 0,
                        ),
                      ) === 'success',
                  }"
                  :style="{
                    width: `${Math.min(
                      getUsagePercentage(
                        (resourcesData.used[
                          resource.key as keyof typeof resourcesData.used
                        ] as number) || 0,
                        (resourcesData.limits[
                          resource.key as keyof typeof resourcesData.limits
                        ] as number) || 0,
                      ),
                      100,
                    )}%`,
                  }"
                ></div>
              </div>
            </div>
          </div>
        </div>
        </Card>
      </div>

      <div v-else class="text-muted-foreground py-8 text-center">
        <p class="text-xs">Failed to load resources</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Center the last 3 cards (5th, 6th, 7th) on the second row for large screens */
@media (min-width: 1024px) {
  .resource-grid > :nth-child(5) {
    grid-column-start: 1;
  }
  .resource-grid > :nth-child(6) {
    grid-column-start: 2;
  }
  .resource-grid > :nth-child(7) {
    grid-column-start: 3;
  }
}
</style>
