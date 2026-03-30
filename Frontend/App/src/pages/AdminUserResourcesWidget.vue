<script setup lang="ts">
import { ref, onMounted } from "vue";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Loader2, Save, ExternalLink, HardDrive, Cpu, Database, Server, Archive, Network } from "lucide-vue-next";
import {
  useResourcesAdminAPI,
  type UserResourcesDetail,
} from "@/composables/useResourcesAdminAPI";
import { useToast } from "vue-toastification";

function parseUserIdFromSearch(): number | null {
  const raw = new URLSearchParams(window.location.search).get("userId");
  if (!raw) return null;
  const n = parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

const userId = ref<number | null>(parseUserIdFromSearch());
const toast = useToast();
const { getUserResources, updateUserResources } = useResourcesAdminAPI();

const loading = ref(true);
const saving = ref(false);
const loadError = ref<string | null>(null);
const detail = ref<UserResourcesDetail | null>(null);

const form = ref({
  memory_limit: 0,
  cpu_limit: 0,
  disk_limit: 0,
  server_limit: 0,
  database_limit: 0,
  backup_limit: 0,
  allocation_limit: 0,
});

const formatBytes = (mb: number): string => {
  if (mb === 0) return "0 MB";
  if (mb >= 1024) return `${(mb / 1024).toFixed(2)} GB`;
  return `${mb.toFixed(0)} MB`;
};

async function load() {
  const id = userId.value;
  if (id == null) {
    loading.value = false;
    loadError.value =
      "Missing user id. Open this page from admin user edit with Billing Resources installed.";
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    const d = await getUserResources(id);
    detail.value = d;
    const r = d.resources;
    form.value = {
      memory_limit: r.memory_limit || 0,
      cpu_limit: r.cpu_limit || 0,
      disk_limit: r.disk_limit || 0,
      server_limit: r.server_limit || 0,
      database_limit: r.database_limit || 0,
      backup_limit: r.backup_limit || 0,
      allocation_limit: r.allocation_limit || 0,
    };
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : "Failed to load resources";
    detail.value = null;
  } finally {
    loading.value = false;
  }
}

async function save() {
  const id = userId.value;
  if (id == null) return;
  for (const [key, value] of Object.entries(form.value)) {
    if (value < 0) {
      toast.error(`${key.replace(/_/g, " ")} must be non-negative`);
      return;
    }
  }
  saving.value = true;
  try {
    const updated = await updateUserResources(id, { ...form.value });
    detail.value = updated;
    toast.success("Resource limits saved");
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to save");
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  load();
});
</script>

<template>
  <div class="p-4 md:p-5 text-foreground">
    <div
      v-if="userId == null"
      class="rounded-lg border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
    >
      {{ loadError || "No user id in widget context." }}
    </div>

    <div v-else-if="loading" class="flex items-center justify-center gap-2 py-16 text-muted-foreground">
      <Loader2 class="h-6 w-6 animate-spin" />
      <span class="text-sm">Loading resource limits…</span>
    </div>

    <div
      v-else-if="loadError && !detail"
      class="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
    >
      {{ loadError }}
    </div>

    <div v-else-if="detail" class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-sm font-medium">{{ detail.username }}</p>
          <p class="text-xs text-muted-foreground break-all">{{ detail.email }}</p>
        </div>
        <a
          href="/admin/resources/management"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
        >
          Resources admin
          <ExternalLink class="h-3.5 w-3.5 opacity-70" />
        </a>
      </div>

      <p class="text-xs text-muted-foreground">
        Memory and disk are in <strong>MB</strong>; CPU limit is <strong>percent</strong>. Same fields as
        Resource Management → Users.
      </p>

      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <HardDrive class="h-3.5 w-3.5" />
            Memory (MB)
          </div>
          <Label class="sr-only" for="br-mem">Memory MB</Label>
          <Input id="br-mem" v-model.number="form.memory_limit" type="number" min="0" step="1" />
          <p class="text-xs text-muted-foreground mt-1.5">≈ {{ formatBytes(form.memory_limit) }}</p>
        </Card>
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Cpu class="h-3.5 w-3.5" />
            CPU (%)
          </div>
          <Label class="sr-only" for="br-cpu">CPU</Label>
          <Input id="br-cpu" v-model.number="form.cpu_limit" type="number" min="0" max="1000" step="1" />
        </Card>
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Database class="h-3.5 w-3.5" />
            Disk (MB)
          </div>
          <Label class="sr-only" for="br-disk">Disk MB</Label>
          <Input id="br-disk" v-model.number="form.disk_limit" type="number" min="0" step="1" />
          <p class="text-xs text-muted-foreground mt-1.5">≈ {{ formatBytes(form.disk_limit) }}</p>
        </Card>
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Server class="h-3.5 w-3.5" />
            Servers
          </div>
          <Label class="sr-only" for="br-srv">Servers</Label>
          <Input id="br-srv" v-model.number="form.server_limit" type="number" min="0" step="1" />
        </Card>
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Database class="h-3.5 w-3.5" />
            Databases
          </div>
          <Label class="sr-only" for="br-db">Databases</Label>
          <Input id="br-db" v-model.number="form.database_limit" type="number" min="0" step="1" />
        </Card>
        <Card class="p-4 border-border/60 bg-card/40">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Archive class="h-3.5 w-3.5" />
            Backups
          </div>
          <Label class="sr-only" for="br-bu">Backups</Label>
          <Input id="br-bu" v-model.number="form.backup_limit" type="number" min="0" step="1" />
        </Card>
        <Card class="p-4 border-border/60 bg-card/40 sm:col-span-2 lg:col-span-3">
          <div class="flex items-center gap-2 text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
            <Network class="h-3.5 w-3.5" />
            Allocations / ports
          </div>
          <Label class="sr-only" for="br-all">Allocations</Label>
          <Input id="br-all" v-model.number="form.allocation_limit" type="number" min="0" step="1" />
        </Card>
      </div>

      <Button :disabled="saving" class="w-full sm:w-auto" @click="save">
        <Loader2 v-if="saving" class="mr-2 h-4 w-4 animate-spin" />
        <Save v-else class="mr-2 h-4 w-4" />
        Save limits
      </Button>
    </div>
  </div>
</template>
