<?php
/**
 * Field reference table for API docs.
 *
 * @var list<array{field: string, type: string, required: bool, description: string}> $fields
 */
if (empty($fields)) {
    return;
}
?>
<div class="api-ref-table-wrap">
    <table class="table api-ref-table">
        <thead>
            <tr>
                <th>Field</th>
                <th>Type</th>
                <th>Required</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fields as $row): ?>
            <tr>
                <td><code><?= htmlspecialchars($row['field']) ?></code></td>
                <td><code class="api-ref-type"><?= htmlspecialchars($row['type']) ?></code></td>
                <td><?= !empty($row['required']) ? 'Yes' : 'No' ?></td>
                <td><?= htmlspecialchars($row['description']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
